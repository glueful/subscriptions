# Subscriptions v1 -- Tenant Subscriptions & Entitlements -- Design Note

**Status:** Design locked; implemented and verified in `glueful/subscriptions` v1. All decisions (S-ns, S-id, S-rl, S1-S12) resolved -- see Decisions. Reflects the entitlement seam's **promotion to framework core** (contract consumed, not shipped here). Boundary-inherited decisions are in **Resolved up front**.
**Date:** 2026-06-09
**Repo:** `glueful/subscriptions` (standalone extension). This spec is self-contained.
**Companions:** Consumes the **locked** Payvia v-next contract (`payvia/docs/superpowers/specs/2026-06-09-payvia-vnext-design.md`). The cross-cutting parent is the framework boundary note (`framework/docs/superpowers/specs/2026-06-08-subscriptions-payvia-boundary-design.md`); this spec restates the constraints it needs so it stands alone.

---

## Why this spec exists

Glueful already ships most of the reusable SaaS spine -- tenancy, users, auth (aegis), payments (payvia). The missing piece is **tenant subscription lifecycle and entitlement resolution**: which plan a tenant is on, whether that subscription is active/trialing/past-due/canceled, which features and limits the plan grants, and how that state stays in sync with the payment provider.

`glueful/subscriptions` fills exactly that gap. It owns the **entitlement plan**, the **app-facing tenant subscription**, and **per-tenant overrides**, and exposes one small runtime value -- `EntitlementCheckerInterface` -- that app code, middleware, rate limiting, and other extensions consume without touching subscription internals.

Crucially, **entitlements decouple from payment**: a tenant subscription can exist with no payment object at all (a free tier, an in-app trial with no card, a comped enterprise account, an internal tenant). Entitlement resolution depends only on the tenant subscription + plan, never on a live provider object -- so the whole package works with **no `glueful/payvia` installed**. When Payvia *is* present, Subscriptions links priced plans and consumes normalized provider events to keep `status` current.

## Goals

1. A tenant subscription schema: `subscriptions`, `subscription_overrides`, `subscription_events`.
2. A config-driven entitlement catalog with per-tenant overrides.
3. `EntitlementCheckerInterface` + an allow-all `NullEntitlementChecker`, with a real DB-backed checker bound by the extension.
4. A `RequireEntitlement` route middleware + a thin current-tenant convenience.
5. Lifecycle projection from Payvia's normalized events + a `subscriptions:reconcile` recovery path.
6. Full free/trial/comp support with **no Payvia dependency**.

## Non-goals

- **Usage metering / quota consumption** (atomic counters, reset windows, Redis) -- deferred to v1.1/v2 as a bounded `UsageMeterInterface`. v1 ships *stateless* entitlement checks and numeric limits only.
- **DB-defined entitlement plans** -- v1 is config-driven; a plan-management CMS is later.
- **Payment gateway calls / raw webhook parsing** -- owned by Payvia.
- **Defining the entitlement contract** -- it now lives in framework core (`Glueful\Entitlements\`); this extension only consumes it (the promotion happened -- see Resolved up front).
- **Checkout / signup / upgrade UX** -- app code.
- **An HTTP admin API** -- v1 exposes a programmatic `SubscriptionService` + CLI (see S8).

---

## Resolved up front (inherited from the boundary note -- do not re-litigate)

| Topic | Resolution |
|---|---|
| Contract namespace | `Glueful\Entitlements` (`Plans` conflates with the catalog; a generic name collides with aegis/authz). |
| Contract signature | Explicit `tenantUuid` first, not a context bag -- mirrors `PermissionManager::can($userUuid, ...)`. |
| Default behavior | **Allow-all / unlimited.** Entitlements are commercial gates, not security boundaries -- absent must never lock an app out (opposite of aegis/tenancy fail-closed). |
| Tenancy coupling | `tenant_uuid` is an **opaque indexed key, no FK** (mirrors tenancy's FK-less `user_uuid`). Tenancy is a **soft** dependency -- Subscriptions needs *a* tenant uuid, not that specific extension. |
| Payvia coupling | **Soft / optional.** Entitlement resolution never needs a live payment object. |
| `status` semantics | An **eventually-consistent projection** of provider state via Payvia events, with a reconcile path. |
| Minimum tables | `subscriptions`, `subscription_overrides`, `subscription_events`. |
| Usage metering | **Out of v1** -> v1.1/v2. |
| Core seam promotion | **Done (contract only).** `EntitlementCheckerInterface` + `NullEntitlementChecker` are promoted to framework core (`Glueful\Entitlements\`); this extension **consumes** the core contract, binds `DefaultEntitlementChecker` over it, and adds the `EntitlementTierResolver` rate-limit bridge as the first consumer. Override of the core Null default relies on the framework container-precedence fix. |
| Orthogonal to aegis | Entitlements = commercial paywall gates (absent-allow); aegis = authorization (fail-closed). They compose; they do not share a gate. |

---

## Dependencies & boundary (self-contained)

- **Soft dep on `glueful/tenancy`** (`suggest`, not `require`). Subscriptions stores `tenant_uuid` as an opaque indexed string with no FK. A thin `CurrentTenant` convenience reads tenancy's `CurrentContext` when present; the checker itself always takes an explicit `tenantUuid`, so jobs/CLI/webhooks work with no request context.
- **Soft dep on `glueful/payvia`** (`suggest`, not `require`). Without Payvia: free/trial/comp/manual subscriptions work fully; `payvia_*` columns stay null. With Payvia: link priced plans, consume `PaymentProviderEvent`, read/reconcile `gateway_subscriptions`.
- **Orthogonal to aegis.** A gated action may require *both* an aegis permission (can this user?) and an entitlement (does this tenant's plan include it?). Separate checks, separate failure modes.

```
require:     php only
require-dev: glueful/framework ^1.54.0   (the release that ships the Glueful\Entitlements seam)
suggest:     glueful/tenancy, glueful/payvia
namespace:   Glueful\Extensions\Subscriptions\   (single root; the contract comes from core's Glueful\Entitlements\)
```

> **Decision S-ns -- contract namespace placement (RESOLVED: in core).** The contract `Glueful\Entitlements\Contracts\EntitlementCheckerInterface` + `NullEntitlementChecker` are **promoted to framework core**, not shipped in this extension. So there is **no second PSR-4 root** -- the extension has a single root (`Glueful\Extensions\Subscriptions\`) and *consumes* the core contract. The framework floor is pinned to the release that ships the seam. (Supersedes the earlier "ship inside the extension behind a second root" plan now that promotion happened.)

---

## The entitlement contract (the reusable runtime value)

The contract and its allow-all default are **provided by framework core** (`Glueful\Entitlements\`) as of the entitlement-seam release -- this extension consumes them:

```php
namespace Glueful\Entitlements\Contracts;   // shipped by framework core, NOT this extension

interface EntitlementCheckerInterface
{
    /** @param array<string,mixed> $context optional extras (e.g. a resource id) */
    public function allows(string $tenantUuid, string $entitlement, array $context = []): bool;

    /** @param array<string,mixed> $context optional extras (e.g. a resource id) */
    public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int;
}
```

- **`NullEntitlementChecker`** (allow-all / unlimited) is the **core** default, bound by `CoreProvider` when no provider overrides it. This extension does not ship it.
- **`DefaultEntitlementChecker`** (DB + config backed) is what this extension binds **over** the core interface -- overriding the core Null default (last-wins, enabled by the framework container-precedence fix). It resolves a tenant's plan + overrides (below).
- **`EntitlementTierResolver`** (this extension) is the first cross-cutting **consumer**: it implements the framework's `TierResolverInterface` and maps a tenant's entitlements to a rate-limit tier, bound over the default `TierResolver`. The cross-domain wiring lives here, not in core (the seam rule: promote the contract, keep consumers extension-side).

> **Decision S3 -- `allows()` / `limit()` semantics; *missing* vs *explicit unlimited*.** Values are mixed-type (booleans, ints) and a key may be **absent** from the resolved map (a typo like `reports.exports`, or simply never configured). Absent must **not** read as unlimited -- otherwise a typo silently grants access. So absent and explicit-unlimited are distinct:
> - **Key absent** from the resolved map -> `allows = false`, `limit = 0`. **Deny.** (Implementation: test presence with `array_key_exists`, *not* `isset`, so an explicit `null` is not mistaken for missing.)
> - **`false` / `0`** -> `allows = false`, `limit = 0`.
> - **`true` or explicit `null`** -> `allows = true`, `limit = null` (**unlimited** -- must be configured explicitly).
> - **int `n > 0`** -> `allows = true`, `limit = n`.
>
> This deny-on-absent rule is **key-level** and does not contradict the **package-level** allow-all default: when `glueful/subscriptions` is *absent*, `NullEntitlementChecker` allows everything; once it's *installed with a catalog*, an undefined key is a misconfiguration and denies. To grant a key to everyone, set it `true`/unlimited in `default_plan`. Recommendation: as above.

---

## Data model

All tables: our own `uuid` columns are 12-char NanoIDs (house convention -- we generate them). `tenant_uuid` is an **external, opaque** id, so it is **not** assumed to be a 12-char NanoID -- it is widened to `string(64)` and indexed with **no FK** (S-id). No cross-package FKs. Migrate at `MigrationPriority::DEPENDENT`, source `glueful/subscriptions` (S9).

> **Decision S-id -- `tenant_uuid` column width.** Tenancy is a *soft* dep and the tenant id is opaque, so it can't be assumed to be a 12-char NanoID -- an app may feed a 36-char UUID or another external id. Recommendation: **`string(64)`** -- comfortably fits NanoID, UUID, and most external ids while staying index-light (a unique/indexed key). Use `string(191)` instead if you expect long external tenant ids.

### `subscriptions` -- one current, app-facing subscription per tenant

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk autoincrement | |
| `uuid` | `string(12)` unique | |
| `tenant_uuid` | `string(64)` | Opaque external id, indexed, **no FK** (S-id). **Unique** -- one current subscription per tenant (S1). |
| `plan_key` | `string(64)` | Key into the config entitlement catalog (`free`, `pro`, ...). |
| `status` | `string(20)` | `active`, `trialing`, `past_due`, `canceled`, `incomplete`. (Dunning "grace" is `past_due` + `grace_ends_at` in the future, not a separate status.) Projection of provider state when Payvia-linked; set directly otherwise. |
| `trial_ends_at` | timestamp nullable | For `trialing`. |
| `current_period_end` | timestamp nullable | When the paid/trial period ends (drives the dunning-grace window / downgrade). |
| `grace_ends_at` | timestamp nullable | End of dunning grace before downgrade. |
| `canceled_at` | timestamp nullable | |
| `payvia_gateway` | `string(50)` nullable | All `payvia_*` nullable -- present only when payment-linked. |
| `payvia_customer_id` | `string(191)` nullable | |
| `payvia_subscription_id` | `string(191)` nullable | The provider-subscription link Subscriptions **owns** (boundary D7). **Unique with `payvia_gateway`** -- a provider-sub id is only unique within a gateway. |
| `payvia_priced_plan_uuid` | `string(12)` nullable | FK-less ref to payvia `billing_plans`. |
| `metadata` | json nullable | App metadata. |
| `created_at` / `updated_at` | timestamps | |

- **Unique `tenant_uuid`** (S1) -- current state only; lifecycle history lives in `subscription_events`.
- **Unique `(payvia_gateway, payvia_subscription_id)`** (multiple all-NULL rows allowed for free/comp subs) -- one subscription per provider-sub *and* the indexed lookup path when a Payvia event arrives. The provider-sub id is gateway-scoped, so events carry `gateway()` and lookups match on both columns.

### `subscription_overrides` -- per-tenant entitlement overrides (custom deals)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk autoincrement | |
| `uuid` | `string(12)` unique | |
| `tenant_uuid` | `string(64)` | Opaque external id, indexed, no FK (S-id). |
| `entitlement` | `string(128)` | e.g. `projects.limit`, `reports.export`. |
| `value` | json | Boolean / int / string; same value space as the catalog. |
| `expires_at` | timestamp nullable | Optional time-boxed override. |
| `reason` | `string(255)` nullable | Audit (e.g. "manual enterprise deal #123"). |
| `created_at` / `updated_at` | timestamps | |

- **Unique `(tenant_uuid, entitlement)`.** An override replaces the plan's value for that key.

### `subscription_events` -- append-only lifecycle log / projection history

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk autoincrement | |
| `uuid` | `string(12)` unique | |
| `tenant_uuid` | `string(64)` | Opaque external id, indexed, no FK (S-id). |
| `type` | `string(40)` | `created`, `plan_changed`, `status_changed`, `canceled`, `reconciled`, ... |
| `from_status` / `to_status` | `string(20)` nullable | For transitions. |
| `source` | `string(20)` | `manual`, `payvia_event`, `reconcile`. |
| `payvia_gateway` | `string(50)` nullable | The gateway that produced the event (from `gateway()`); part of the dedupe key. |
| `payvia_logical_event_key` | `string(191)` nullable | The Payvia `logical_event_key` that drove this. **Unique with `payvia_gateway` when non-null** -- the DB enforces per-gateway dedupe; nulls (manual/reconcile rows) are exempt. |
| `data` | json nullable | Snapshot / detail. |
| `created_at` | timestamp | |

- **Index `(tenant_uuid, created_at)`** for history reads.
- **Unique index on `(payvia_gateway, payvia_logical_event_key)` (multiple all-NULL rows allowed)** -- the DB *enforces* **per-gateway** idempotency (S6): two concurrent handlers of the same gateway + logical event race on the insert and the loser no-ops, which a plain index could not guarantee. The same provider logical key can recur across gateways, so dedupe is gateway-scoped. MySQL/Postgres/SQLite permit multiple NULLs, so manual/reconcile rows are unconstrained. **Claim-first ordering:** the listener inserts this row as the *atomic claim* and projects the subscription **only if the insert wins**, inside one transaction -- so a duplicate/concurrent handler that loses the claim rolls back with no projection (e.g. `past_due` grace is never extended twice). Exactly one `payvia_event` row + one projection per logical event.

---

## Entitlement catalog (config-driven, v1)

```php
// config/subscriptions.php
return [
    'default_plan' => 'free',                 // plan for tenants with no/lapsed subscription (S2)
    'plans' => [
        'free' => [
            'entitlements' => [
                'reports.export'  => false,
                'projects.limit'  => 3,
                'team.limit'      => 1,
            ],
        ],
        'pro' => [
            'payvia_priced_plan' => null,      // optional uuid of a payvia billing_plan
            'entitlements' => [
                'reports.export'  => true,
                'projects.limit'  => 50,
                'team.limit'      => 20,
                'api.monthly'     => 100000,
            ],
        ],
    ],
    'grace_days' => 3,                          // dunning grace before downgrade
    'cache' => ['enabled' => true, 'ttl' => 300],
];
```

This versions the product catalog with code and avoids making v1 a plan-management CMS. DB-defined plans are a later feature.

> **Decision S5 -- catalog source.** Config-only in v1 (recommended) vs DB-backed plans now. Config keeps v1 small, diffable, and deploy-reviewed. Recommendation: **config-only**; add an optional DB catalog later behind the same resolution code.

---

## Entitlement resolution

`DefaultEntitlementChecker` resolves a tenant's effective entitlements as:

```
1. Load the tenant's subscription (plan_key + status). None -> default_plan.
2. Pick the effective plan by status:
     active | trialing                          -> the subscription's plan_key
     past_due AND grace_ends_at in the future   -> the subscription's plan_key   (dunning grace)
     past_due AND grace_ends_at passed/absent   -> default_plan                  (downgrade, S2)
     incomplete                                 -> default_plan                  (created but never activated -- no paid access)
     canceled                                   -> default_plan                  (downgrade, S2)
3. Start from that plan's entitlements (config catalog).
4. Apply per-tenant overrides (non-expired) -- override value wins per key.
5. Result = resolved entitlement map for the tenant. Keys absent here deny (S3).
```

- **`allows`/`limit`** read from this resolved map per S3.
- **Status gating (S2):** a lapsed tenant **downgrades to `default_plan`**, it is never locked out -- consistent with absent-allow. Paid entitlements simply fall away. `incomplete` (initial payment never completed) and expired-grace `past_due` both resolve to `default_plan`; `past_due` keeps paid access **only** while `grace_ends_at` is still in the future.
- **Caching (S12):** the resolved map is cached per tenant under a key composed of `tenant_uuid` + config-catalog version + the tenant's `subscriptions.updated_at` + latest override `updated_at`, so any change invalidates naturally. TTL from config. Cache uses the framework cache; absent a cache driver, resolution is cheap enough to run uncached.

> **Decision S2 -- lapsed-subscription behavior.** Options: (a) **downgrade to `default_plan`** (recommended -- absent-allow aligned, tenant keeps free-tier access); (b) deny all paid entitlements but no default (harsher); (c) keep paid entitlements until `current_period_end` then downgrade. Recommendation: **(a)**, with `grace_days` keeping paid access through dunning before the downgrade.

> **Decision S11 -- trials.** `trialing` status + `trial_ends_at`; trial entitlements = the trialed plan's entitlements (recommended) rather than a separate trial plan. A trial with no card needs no Payvia object. Recommendation: **plan-as-trialed**; trial expiry transitions to `default_plan` (or `past_due` if a provider charge is pending).

---

## `RequireEntitlement` middleware + current-tenant convenience

```php
#[RequireEntitlement('reports.export')]      // or ->middleware(['require_entitlement:reports.export'])
```

- Resolves the current tenant via the `CurrentTenant` convenience (reads tenancy's `CurrentContext`), then calls `allows()`. On deny -> `403` (with an `entitlement` error code so clients can prompt an upgrade).
- The checker is tenant-explicit, so the same gate works in jobs/CLI by passing `tenantUuid` directly; only the *middleware* needs a request-scoped tenant.
- If **no tenant resolves**, the gate returns `403` by default -- a wired paywall with a broken precondition is a misconfiguration, not an open door. Set `subscriptions.permissive_middleware = true` to make it no-op (allow) instead. (S4)

> **Decision S4 -- unresolvable tenant in `RequireEntitlement`.** An *explicit* route gate that can't resolve a tenant is a **misconfiguration**, and failing open there leaks paid features. So -- unlike the *package-level* allow-all default -- the explicit gate fails **closed**: (a) **`403`/config error by default** (recommended); (b) fail open + warn. Recommendation: **(a)**, with a `subscriptions.permissive_middleware` opt-in flag for apps that deliberately want the gate to no-op when no tenant is present. The asymmetry is intentional: *absent package* -> allow-all (entitlements aren't a security boundary), but *present, explicit gate with no tenant* -> deny (don't leak a wired paywall). Pair entitlement gates with an aegis permission when you need a real security boundary.

---

## Lifecycle: consuming Payvia events

When Payvia is installed, Subscriptions self-registers a listener on `PaymentProviderEvent` (S7) and projects provider state onto the tenant subscription:

```
PaymentProviderEvent (normalized) -> switch on $e->event->type():

  subscription.created    -> ensure link (payvia_subscription_id), set status active/trialing
  subscription.updated    -> update status / period / plan link
  subscription.past_due   -> status past_due, set grace_ends_at = now + grace_days
  subscription.canceled   -> status canceled, canceled_at
  payment.succeeded       -> if trialing/past_due, settle to active; clear grace_ends_at
  invoice.paid            -> same settle path

each handled event:
  - find tenant subscription by (event.gateway(), payvia_subscription_id)  (the map Subscriptions owns)
  - claim-first (one transaction): insert the subscription_events row -- unique (payvia_gateway, payvia_logical_event_key) is the atomic gate
  - project the subscription ONLY if the claim insert wins; a duplicate/concurrent loser rolls back -> no projection
  (read-side dedupe on (gateway, logicalEventKey) is just a cheap early-out; the transactional claim is the real gate)
```

- **Mapping provider-sub -> tenant** is Subscriptions' job (boundary D7): the link is set when the subscription is created (Subscriptions wrote `payvia_subscription_id`), or recovered from provider metadata carrying `tenant_uuid`. Payvia stays tenancy-agnostic.
- **Idempotency** is defense-in-depth: Payvia's outbox already dispatches each logical event once; Subscriptions additionally skips any `logical_event_key` already in `subscription_events`.
- **Consumers depend only on `normalized()`** -- never raw provider payloads.

> **Decision S6 -- event idempotency depth.** Rely solely on Payvia's outbox (single dispatch), or also dedupe on `subscription_events.payvia_logical_event_key` (recommended). The extra check is cheap and survives at-least-once delivery if Payvia is ever reconfigured or replays. Recommendation: **both** -- belt and suspenders on money-adjacent state.

> **Decision S7 -- listener registration.** The extension self-registers the Payvia listener in `boot()` when `PaymentProviderEvent` exists (recommended -- works out of the box, no app wiring) vs requiring the app to add it to `config/events.php`. Recommendation: **self-register**, guarded by class-exists so it's inert without Payvia.

---

## Reconciliation: `subscriptions:reconcile`

`status` is an eventually-consistent projection, so missed/out-of-order webhooks need a recovery path:

- **`php glueful subscriptions:reconcile [--tenant=UUID]`** -- for each tenant subscription with a `payvia_subscription_id`, pull authoritative provider state via Payvia's single-object `reconcile()` / `gateway_subscriptions` read, re-derive `status`, fix drift, append a `reconciled` event.
- **Cadence (S10):** manual CLI by default (zero-infra); an optional scheduled run when the app wires it into the framework scheduler. Subscriptions owns the cadence; Payvia exposes only the per-object pull.

> **Decision S10 -- reconcile cadence.** Default to **manual CLI + opt-in scheduled job** (recommended) rather than forcing a periodic sweep. Apps with Payvia and real traffic enable the schedule; zero-infra installs don't pay for it. Recommendation: ship the command + a documented scheduler hook; default off.

---

## Service surface

- **`EntitlementCheckerInterface`** (allows/limit) -- the primary runtime value; consumed by app code, `RequireEntitlement`, rate-limit tiers, other extensions.
- **`SubscriptionService`** -- programmatic lifecycle: `current(tenantUuid)`, `start(tenantUuid, planKey, opts)`, `changePlan(tenantUuid, planKey)`, `cancel(tenantUuid, atPeriodEnd)`, `reconcile(tenantUuid)`. All work with **no Payvia** (free/trial/comp); `payvia_*` populated only when a payment flow links one.
- **CLI:** `subscriptions:reconcile`, plus `subscriptions:show --tenant=`, `subscriptions:set-plan --tenant= --plan=` for ops.
- **No HTTP admin API in v1** (S8) -- app code calls `SubscriptionService`; add HTTP later if an admin UI needs it (mirrors Payvia D10).

> **Decision S8 -- HTTP admin API in v1?** Defer (recommended) -- `SubscriptionService` + CLI cover programmatic and ops needs; checkout/upgrade UX is app-specific anyway. Recommendation: **defer.**

---

## Service provider wiring (`SubscriptionsServiceProvider`)

- **`services()`** -- bind `EntitlementCheckerInterface => DefaultEntitlementChecker` (shared); register `SubscriptionService`, repositories, the `RequireEntitlement` middleware (alias `require_entitlement`), and console commands.
- **`register()`** -- `mergeConfig('subscriptions', ...)`; `loadMigrationsFrom(__DIR__.'/../migrations', MigrationPriority::DEPENDENT, 'glueful/subscriptions')`.
- **`boot()`** -- `registerMeta`; `discoverCommands`; **conditionally** register the `PaymentProviderEvent` listener if the class exists (S7). No routes in v1.

The `NullEntitlementChecker` is *not* bound when the extension is installed -- the real checker wins. Null exists for tests and as the default a future core seam would bind when Subscriptions is absent.

---

## What it consumes from Payvia (the locked contract)

1. **Priced plans** -- read `billing_plans` by uuid for `payvia_priced_plan_uuid` linkage. Entitlements are *not* read from here (`features` is deprecated on Payvia's side).
2. **Normalized events** -- subscribe to `PaymentProviderEvent`; switch on `type()`; depend only on `normalized()` and `logicalEventKey()`.
3. **Provider subscriptions** -- read `gateway_subscriptions` and call Payvia's `reconcile()` for drift recovery.
4. **Ownership** -- Subscriptions owns the `payvia_subscription_id <-> tenant_uuid` map; Payvia never imports tenancy.

## Versioning

- First release **1.0.0** (greenfield). `require`: php only. `require-dev`: `glueful/framework ^1.54.0` (the release that ships the `Glueful\Entitlements` seam). `suggest`: `glueful/tenancy`, `glueful/payvia`. `extra.glueful.requires.glueful: >=1.54.0`. Ships **after** the framework seam release (release-first).

## Out of scope (explicit)

- Usage metering / quota consumption counters (v1.1/v2, `UsageMeterInterface`).
- DB-defined entitlement plans.
- HTTP admin API; checkout/upgrade UX.
- Changing the core entitlement seam (it ships in the framework; this extension only consumes/overrides it).
- Any gateway/provider API calls (Payvia owns).

## Build sequence (phases -- detailed in the implementation plan)

1. **Package skeleton** -- composer (`glueful/subscriptions`, framework floor `^1.54.0`), `SubscriptionsServiceProvider`, single PSR-4 root (`Glueful\Extensions\Subscriptions\`; contract consumed from core), config stub. (S-ns)
2. **Contract + null + catalog** -- `EntitlementCheckerInterface`, `NullEntitlementChecker`, `config/subscriptions.php` catalog shape. (S3, S5)
3. **Schema** -- `subscriptions` / `subscription_overrides` / `subscription_events` migrations at `DEPENDENT`. (S1, S9)
4. **Resolution + checker** -- `DefaultEntitlementChecker` (plan + overrides + status downgrade + cache). (S2, S11, S12)
5. **Middleware** -- `RequireEntitlement` + `CurrentTenant` convenience + fail-closed default + `permissive_middleware` flag. (S4)
6. **Lifecycle + reconcile** -- `SubscriptionService`, Payvia listener (conditional), `subscriptions:reconcile` + ops CLI. (S6, S7, S8, S10)
7. **Wiring & docs** -- provider registrations, README/CHANGELOG, the "consumes Payvia" section, migration/usage guide.

## Decisions (all resolved -- locked for implementation)

No open architecture decisions remain. Do not re-litigate these in the implementation plan. Boundary-inherited decisions (namespace, contract signature, allow-all default, opaque FK-less `tenant_uuid` + soft tenancy dep, soft payvia dep, projection+reconcile `status`, three tables, usage-metering deferral, **core-promotion now DONE -- contract in core**, aegis orthogonality) are in the **Resolved up front** table near the top.

| # | Decision | Resolution |
|---|---|---|
| S-ns | Contract location | **In framework core** (`Glueful\Entitlements\`); extension consumes it -- single PSR-4 root, no local contract. Floor `^1.54.0`. |
| S-rl | Rate-limit bridge mapping (`EntitlementTierResolver`) | **Tier-flag** for v1 -- `allows('rate.tier.{tier}')` over config-ordered `rate_tiers` (highest-first); `TierManager` owns the numbers. Numeric-quota (`limit('api.rate.{window}')`) deferred to v1.1+. |
| S-id | `tenant_uuid` column width | `string(64)` -- fits NanoID/UUID/opaque external ids (not assumed 12-char); `191` if longer ids expected |
| S1 | `subscriptions` cardinality | One current row per tenant (unique `tenant_uuid`); history in events |
| S2 | Lapsed-subscription behavior | Downgrade to `default_plan`; `grace_days` keeps paid access through dunning |
| S3 | `allows()`/`limit()` semantics | **Absent key denies** (`false`/`0`); explicit `true`/`null` = unlimited; presence via `array_key_exists` |
| S4 | Unresolvable tenant in middleware | Fail **closed** `403` by default; `permissive_middleware` opt-in to no-op |
| S5 | Catalog source | Config-only in v1; DB catalog later |
| S6 | Event idempotency depth | Payvia outbox **and** `subscription_events` dedupe |
| S7 | Listener registration | Self-register in `boot()`, guarded by class-exists |
| S8 | HTTP admin API in v1 | Defer; `SubscriptionService` + CLI |
| S9 | Migration priority | `DEPENDENT` (no FKs; safe late) |
| S10 | Reconcile cadence | Manual CLI + opt-in scheduled job; default off |
| S11 | Trials | `trialing` + `trial_ends_at`; trial uses the trialed plan's entitlements |
| S12 | Resolution cache | Framework cache; key by tenant + catalog version + subscription/override `updated_at` |
