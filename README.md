# Glueful Subscriptions

Tenant subscriptions, plans, and stateless entitlements with numeric limits,
trials, overrides, and lifecycle sync for Glueful SaaS apps.

Subscriptions is a subscription lifecycle and entitlement resolution layer. A
tenant's effective entitlement map is resolved from a plan catalog plus
per-tenant overrides, gated by the subscription's status -- never from a live
payment object. Entitlement checks are stateless reads (allow/deny plus an
optional numeric limit); usage metering and quota *consumption* are out of
scope and on the roadmap.

## Install

```bash
composer require glueful/subscriptions
php glueful extensions:enable subscriptions
php glueful migrate:run
php glueful subscriptions:plans:import-config
```

The import step is **required**, not optional: since 2.0 the plan catalog is
database-authoritative and `config('subscriptions.plans')` is only a *seed*, so
until it is imported `subscription_plans` is empty and **every entitlement
resolves to an empty map**. The command is create-missing and safe to re-run
(it never overwrites an edited plan) -- re-run it whenever you add a plan to
config. If you skip it, the resolver logs an explicit
`subscriptions.default_plan_unresolvable` error naming the unresolvable
`default_plan` and this command.

Requires `glueful/framework ^1.55.0`. (The `Glueful\Entitlements` seam and the
container-precedence fix this extension relies on shipped in 1.54.0; 1.55.0 is
required as the security-hardened baseline.)

## Two product layers

Since 2.0, this extension models two coexisting products that **never share
entitlements**. They are separate models, not configuration modes -- there is
no per-install "subject mode", and both run in the same installation at the
same time:

| Layer | Subscriber | Catalog | Controls |
| --- | --- | --- | --- |
| **Workspace subscription** (1.x's only product) | a tenant/workspace | global platform catalog (`audience='tenant'`) | workspace capabilities, limits, API rate tiers |
| **User membership** (new in 2.0) | a user *within* one workspace | that workspace's own membership catalog (`audience='user'`) | paywalls, member benefits, recurring content access |

- **Tenant resolver** (unchanged from 1.x): `EntitlementResolver` /
  `DefaultEntitlementChecker` / the `require_entitlement` middleware.
- **Member resolver** (new): `MemberEntitlementResolver` / the
  `require_member_entitlement` middleware -- see
  [Memberships](#memberships-workspace-scoped-user-subscriptions) below.

A workspace's membership plan entitlements never appear in the tenant
checker's output, and a tenant's platform-plan entitlements never appear in a
member's map -- each resolver reads its own scoped catalog and its own
subject-scoped overrides. Memberships are completely **inert** until a host
binds a `SubjectResolverInterface` that can vouch for real users; see
"Enabling memberships" below.

## The decoupling invariant

This package works fully with **no `glueful/payvia` and no `glueful/tenancy`
installed**. Both are soft dependencies, probed at runtime via `class_exists`:

- **No payvia:** free / trial / comp subscriptions work end to end through
  `SubscriptionService` (start, change plan, cancel). `reconcile` is a safe
  no-op. No provider listener is registered.
- **No tenancy:** the entitlement checker still works anywhere you can supply a
  tenant uuid explicitly (jobs, CLI, webhooks). Only the conveniences that need
  a *current* tenant (the `require_entitlement` middleware, the rate-tier
  bridge) degrade: the middleware fails closed with 403 by default (opt out via
  `subscriptions.permissive_middleware`), the tier bridge delegates to the
  framework's default resolver.

### Note for tenancy hosts (new in 2.0)

2.0 preserves every 1.x *API* unchanged, but if you run `glueful/tenancy` (or
anything else binding the `TenantTableRegistry` / `TenantContextRunner`
contracts) two things about the integration are new:

- **Table registration.** Boot registers exactly `subscriptions`,
  `subscription_overrides`, and `subscription_events` as tenant-owned tables, so
  your tenancy layer can scope, stamp, and purge them. `subscription_plans` and
  `subscription_provider_event_receipts` are deliberately **not** registered:
  the plan catalog is platform/workspace-scoped by its own
  `(audience, owner_tenant_uuid)` columns, and receipts are claimed before any
  tenant is known.
- **Entitlement reads run in system mode.** Because those three tables are
  registered, an ambient tenant would otherwise be injected into every read of
  them. Both `EntitlementResolver::resolveMap()` and
  `MemberEntitlementResolver::resolveMap()` (like
  `SubscriptionEventProjector::project()`) therefore run their repository reads
  through `TenantContextRunner::runAsSystem()`. This is correct and deliberate:
  those APIs are explicitly *parameterized* by tenant -- resolving another
  tenant's entitlements from a job, a CLI, or a request running in a different
  tenant's context is a documented, supported call -- and their `WHERE` clauses
  already pin the exact subject triple. Without it, a cross-tenant
  `allows($otherTenant, ...)` would silently return an empty map. Writes
  (`SubscriptionService`'s `…For()` methods) still run through
  `runAsTenant($subject->tenantUuid, ...)`.

## Checking entitlements

This package binds a DB+config-backed `DefaultEntitlementChecker` over the
framework-core contract `Glueful\Entitlements\Contracts\EntitlementCheckerInterface`
(overriding core's allow-all `NullEntitlementChecker` default):

```php
use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;

$checker = app($context, EntitlementCheckerInterface::class);

if ($checker->allows($tenantUuid, 'reports.export')) {
    // gated feature
}

$limit = $checker->limit($tenantUuid, 'projects.limit'); // ?int -- null = unlimited
```

Entitlement values come from the plan catalog merged with active per-tenant
overrides. Overrides win per key; expired overrides are ignored.

| Configured value      | `allows()` | `limit()`            |
| --------------------- | ---------- | -------------------- |
| key absent (typo too) | `false`    | `0`                  |
| `false`               | `false`    | `0`                  |
| `true`                | `true`     | `null` (unlimited)   |
| `null` (explicit)     | `true`     | `null` (unlimited)   |
| int `n > 0`           | `true`     | `n`                  |
| int `0`               | `false`    | `0`                  |

Absent-key-denies is deliberate: a typo in an entitlement name fails closed
instead of silently allowing.

### Status gating

The effective plan is derived from the subscription status before the
entitlement map is built:

| Subscription state                        | Effective plan       |
| ----------------------------------------- | -------------------- |
| none                                      | `default_plan`       |
| `active`                                  | the subscription's   |
| `trialing`                                | the trialed plan's   |
| `past_due`, `grace_ends_at` in the future | the subscription's   |
| `past_due`, grace passed or absent        | `default_plan`       |
| `incomplete`                              | `default_plan`       |
| `paused`                                  | `default_plan`       |
| `canceled`                                | `default_plan`       |

`paused` is accepted from payvia's provider-status vocabulary (via
`subscription.updated` projection or reconcile drift) and resolves to the
default plan: a paused tenant is treated as not entitled to paid features until
the provider resumes the subscription.

## Route middleware

The supported API is the middleware-string form:

```php
$router->get('/reports/export', [ReportController::class, 'export'])
    ->middleware(['require_entitlement:reports.export']);
```

> NOTE: a `#[RequireEntitlement]` route **attribute is NOT shipped** in v1.
> The framework's attribute routing offers no generic attribute->middleware
> bridge for extension attributes (plan blocker B1), so the attribute form is
> deferred. Use the `require_entitlement:<entitlement>` middleware string.

The gate fails closed: no resolvable tenant means 403 unless
`subscriptions.permissive_middleware` is `true`. A denied entitlement returns
403 with an `entitlement` error code so clients can prompt an upgrade.

## Plan catalog

The catalog has ONE source of truth: managed DB plans in `subscription_plans`.
`config/subscriptions.php` holds plan SEEDS -- you import them once with
`php glueful subscriptions:plans:import-config` -- and is **not** a runtime
fallback. A config plan with no matching DB row does not exist as far as
resolution, existence, and assignment are concerned.

Each catalog is also scoped to one `(audience, owner_tenant_uuid)` pair. The
platform catalog is `('tenant', '')`; a workspace's membership catalog is
`('user', <workspace uuid>)`. The same `plan_key` may exist in several scopes and
they never see each other.

Resolution within a scope accepts DB rows with status `active` or `archived`; a
`draft` row exists but does not resolve. When nothing resolves, the entitlement
map is empty and every key denies.

An empty `subscription_plans` table therefore denies everything -- import the
config seeds after migrating. If the plan table is missing entirely (migration
004 has not run), catalog reads catch it and behave as an empty catalog.

Plan assignment is stricter than plan resolution:

| Plan source/status | Resolves for existing tenants | Assignable to new tenants |
| ------------------ | ----------------------------- | ------------------------- |
| DB `active`        | yes                           | yes                       |
| DB `archived`      | yes                           | no                        |
| DB `draft`         | no                            | no                        |
| config only        | no                            | no                        |

Archived is never delete: tenants already on an archived plan keep resolving it.
Draft is pre-publish only; active and archived plans cannot transition back to
draft. An empty entitlement map `{}` is valid and means "deny every entitlement
key."

### Config seed (config/subscriptions.php)

Seeds only -- import them into the platform catalog to make them resolvable.

```php
return [
    'default_plan' => 'free',
    'plans' => [
        'free' => [
            'entitlements' => [
                'reports.export' => false,
                'projects.limit' => 3,
                'team.limit'     => 1,
            ],
        ],
        'pro' => [
            'provider_price_id' => null, // optional provider price/plan id
            'entitlements' => [
                'reports.export' => true,
                'projects.limit' => 50,
                'team.limit'     => 20,
                'api.monthly'    => 100000,
            ],
        ],
    ],
    'rate_tiers' => ['enterprise', 'pro'], // highest-first (rate-limit bridge)
    'grace_days' => 3,                     // dunning grace before downgrade
    'cache' => ['enabled' => true, 'ttl' => 300],
    'permissive_middleware' => false,
    'reconcile' => ['schedule_enabled' => false],
];
```

A lapsed tenant (canceled / incomplete / past_due beyond grace) downgrades to
`default_plan` -- it is never locked out; paid entitlements simply fall away.

## Entitlement overrides

Overrides (the `subscription_overrides` table) win per key over whatever the
plan grants, and may carry an expiry. Since 2.0 an override belongs to a
**subject**, not to a tenant: the row's identity is the full triple
`(tenant_uuid, subject_type, subject_uuid)` plus `entitlement`, which is exactly
the table's unique key (`uniq_override_subject_entitlement`).

Use the shipped writer -- it is the supported way to write this table:

```php
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Subject;

$overrides = new OverrideRepository();

// A workspace-level override (the 1.x meaning: the tenant's own subject).
$overrides->upsertForSubject($context, Subject::tenant($tenantUuid), 'projects.limit', 500);

// A member-level override, scoped to one user inside one workspace.
$overrides->upsertForSubject(
    $context,
    Subject::user($tenantUuid, $userUuid),
    'content.premium',
    false,                              // a DENY override
    expiresAt: '2026-12-31 23:59:59',   // optional
    reason: 'chargeback hold',          // optional
);

$overrides->deleteForSubject($context, Subject::tenant($tenantUuid), 'projects.limit');
```

`upsertForSubject()` inserts or updates the one row identified by that unique,
generates the `uuid`, and json-encodes the value, so booleans, numbers, strings
and arrays all round-trip.

> **Writing the table directly? You must supply the subject columns.**
> 1.x shipped no writer, so hosts inserted
> `(uuid, tenant_uuid, entitlement, value)` by hand. Migration `006` adds
> `subject_type`/`subject_uuid` with defaults `'tenant'`/`''`, and reads match on
> the **full triple** -- so a 1.x-shaped insert lands with `subject_uuid = ''`,
> matches no subject, and is silently ignored. A **deny** override written that
> way therefore GRANTS. A direct insert must now look like:
>
> ```sql
> INSERT INTO subscription_overrides
>   (uuid, tenant_uuid, subject_type, subject_uuid, entitlement, value, expires_at, reason)
> VALUES
>   ('nano12charsid', :tenant_uuid, 'tenant', :tenant_uuid, 'projects.limit', '500', NULL, 'comped');
> ```
>
> For a workspace's own override, `subject_type = 'tenant'` and
> `subject_uuid = tenant_uuid`. For a member, `subject_type = 'user'` and
> `subject_uuid = <user uuid>`. `value` is JSON (`'500'`, `'true'`, `'"gold"'`).

### Administrative override reads

`OverrideRepository::listForSubject($context, $subject)` is the detailed,
administrative counterpart to `activeForSubject()`: it returns **every** row
for the subject's exact triple, expired ones included, ordered by
`entitlement ASC`, each projected to `{entitlement, value, expires_at,
reason, created_at, updated_at}` -- no storage identity field. Use it for an
audit/admin view where `activeForSubject()`'s collapsed `{entitlement:
value}` map (which silently drops anything expired) isn't enough.

```php
$overrides->listForSubject($context, Subject::user($tenantUuid, $userUuid));
```

Like `activeForSubject()`, this is a plain subject-scoped read with no
tenancy opinion of its own -- a caller doing cross-workspace administration
(an admin inspecting a subject it is not currently scoped to) must wrap the
call in `TenantIntegration::runAsTenantOr()` itself.

## Lifecycle via SubscriptionService

```php
use Glueful\Extensions\Subscriptions\SubscriptionService;

$service = app($context, SubscriptionService::class);

// Free/comp/trial -- no provider object needed, all provider_* columns stay NULL.
$service->start($tenantUuid, 'free');
$service->start($tenantUuid, 'pro', [
    'status' => 'trialing',
    'trial_ends_at' => '2026-07-01 00:00:00',
]);

$service->current($tenantUuid);          // ?array (the subscriptions row)
$service->changePlan($tenantUuid, 'pro');
$service->cancel($tenantUuid);                       // at period end (metadata flag)
$service->cancel($tenantUuid, atPeriodEnd: false);   // immediate: status=canceled
$service->reconcile($tenantUuid);        // pull provider truth, when a provider puller is bound
```

Every transition appends a `subscription_events` row (`created`,
`plan_changed`, `canceled`, `reconciled`, or provider event types) with
`from_status` / `to_status` / `source` (`manual`, `provider_event`,
`reconcile`).

## Bulk administrative reads

`SubscriptionService::currentForTenants(array $tenantUuids): array` is a
trusted bulk projection for platform-authority callers (an admin console, a
background report) that already have a normalized, deduplicated tenant list
from their own authoritative directory -- one query,
`WHERE subject_type='tenant' AND tenant_uuid IN (...)`, regardless of how
many UUIDs are requested, up to `MAX_TENANT_BATCH` (100).

```php
$rows = $service->currentForTenants(['tenant-a', 'tenant-b']);
// ['tenant-a' => [...subscription row...], 'tenant-b' => [...]]
// an absent key means that tenant has no subscription -- never a null value
```

Unlike `currentFor()`, this does **not** call
`SubjectResolverInterface::validate()` per UUID -- doing so would reintroduce
the N+1 the batch exists to avoid. That makes it a **trusted projection**:
the caller's tenant UUIDs are assumed to already be server-derived and
authorized (never taken raw from client input), and it must never be mounted
directly as an HTTP batch-by-UUID endpoint. The repository read runs through
`TenantIntegration::runAsSystemOr()` so ambient tenant scoping cannot narrow
the administrative projection.

## Rate-limit tier bridge

`EntitlementTierResolver` implements the framework's `TierResolverInterface`
over the default resolver: plans grant boolean `rate.tier.{tier}` entitlement
flags for the tiers listed in `subscriptions.rate_tiers` (highest-first); the
first granted tier wins, and `TierManager` config owns the numbers. No tenant
or no granted flag delegates to the default resolver -- the bridge is inert
without tenancy. Rate tiers are **tenant-only**: a workspace membership plan's
entitlements never reach this bridge (see Memberships below).

## Memberships (workspace-scoped user subscriptions)

A **membership** is a `user` subject's subscription to a plan owned by ONE
workspace (`audience='user'`, `owner_tenant_uuid=<that workspace>`) -- see
[Two product layers](#two-product-layers). It never shares a catalog, a plan,
or an entitlement map with that workspace's own (`tenant`) subscription.

### Enabling memberships

The shipped `SubjectResolverInterface` default rejects **every** `user`
subject, so memberships are completely inert out of the box. **Binding your
own resolver is the enablement switch** -- there is no config flag:

```php
// In your app's ServiceProvider.
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;

public static function services(): array
{
    return [
        SubjectResolverInterface::class => [
            'class' => YourAppSubjectResolver::class,
            'shared' => true,
            'autowire' => true,
        ],
    ];
}
```

Your resolver's `currentUser()` must return the currently authenticated global
user's uuid (or `null`), and `validate()` must prove a `user` subject's
`(tenant_uuid, subject_uuid)` pairing is a real, existing membership before any
write is allowed to proceed (spec §4).

### Resolving a member's entitlements

```php
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Resolution\MemberEntitlementResolver;

$catalog = PlanCatalog::forScope($context, 'user', $tenantUuid); // THIS workspace only
$resolver = new MemberEntitlementResolver(
    $catalog,
    $subscriptionRepository,
    $overrideRepository,
    $effectivePlanResolver,
);

$map = $resolver->resolveMap($context, $tenantUuid, $userUuid);
```

Unlike the tenant path, a membership has **no implicit default plan**: with no
subscription row, `resolveMap()` starts from an empty entitlement map (an
active user-subject override may still grant complimentary access). Any
`rate.tier.*` key is always stripped from a member map -- rate limiting stays
tenant-only even if a membership plan's entitlements JSON happens to carry one.

### `require_member_entitlement` middleware

```php
$router->get('/articles/{id}', [ArticleController::class, 'show'])
    ->middleware(['require_member_entitlement:content.premium']);
```

Fails closed (403) when the current workspace or the current user cannot be
resolved via `SubjectResolverInterface`, unless
`subscriptions.permissive_middleware` is `true` -- the same flag
`require_entitlement` uses; since 2.0 it governs **both** middlewares.

### Provider-driven memberships

Every provider subscription that backs a membership MUST carry the complete
subject in its metadata: `tenant_uuid`, `subject_type`, `subject_uuid`, and
`plan_uuid`. The projector requires and validates the full triple on
`subscription.created` and rejects an incomplete or invalid one into a
provider-event receipt rather than silently mapping it to the wrong subject.
See
[docs/BRING_YOUR_OWN_PROVIDER.md](docs/BRING_YOUR_OWN_PROVIDER.md#metadata-and-subject-validation)
for the full contract, including which rejection codes commit and which
outcomes are retryable.

## Consumes Payvia (when installed)

Payvia is the **first-party default** and needs zero wiring. It is just one
provider, though: any payment package (or your own app) can drive subscription
state through the same generic seam — see
[docs/BRING_YOUR_OWN_PROVIDER.md](docs/BRING_YOUR_OWN_PROVIDER.md). Subscriptions
*consumes* payvia; payvia stays tenancy-agnostic:

- **Priced plans:** a catalog plan may point at a provider price/plan via
  `provider_price_id`.
- **Provider events:** when `Glueful\Extensions\Payvia\Events\PaymentProviderEvent`
  exists, a thin bridge self-registers as a listener in `boot()` and projects normalized provider
  events (`subscription.created/updated/past_due/canceled`, `payment.succeeded`,
  `invoice.paid`) onto subscription status -- claim-first in one transaction with
  per-gateway logical-event-key dedupe DB-enforced by a unique index, so a
  duplicate or concurrent delivery never re-projects (grace is never extended
  twice).
- **Reconcile:** `subscriptions:reconcile` pulls authoritative provider state
  through payvia's `GatewaySubscriptionService::reconcile($gateway, $gatewaySubscriptionId)`
  and applies drift, recording a `reconciled` event.

Provider-event projection maps:

| Provider event          | Projection                                            |
| ----------------------- | ----------------------------------------------------- |
| `subscription.created`  | link provider sub, status `active`/`trialing`         |
| `subscription.updated`  | status/period drift; settling to active clears grace  |
| `subscription.past_due` | status `past_due`, `grace_ends_at = now + grace_days` |
| `subscription.canceled` | status `canceled`, `canceled_at`                      |
| `payment.succeeded`    | if `trialing`/`past_due` -> `active`, clear grace     |
| `invoice.paid`          | same settle path                                      |

Idempotency is claim-first: a `pending` row in
`subscription_provider_event_receipts` (unique per `(provider_gateway,
provider_logical_event_key)`) is claimed FIRST, then the projection runs in the
SAME transaction, so a duplicate or concurrent delivery rolls back and never
re-projects -- grace can never be extended twice. The subscription mapping is
`(gateway, gateway_subscription_id)`; on `subscription.created` an unlinked row
can be recovered via provider metadata (see [Provider-driven
memberships](#provider-driven-memberships) above for the full
`tenant_uuid`/`subject_type`/`subject_uuid`/`plan_uuid` metadata contract).

> **Webhook endpoints MUST treat an uncaught `UnmappedProviderSubscriptionException`
> as a signal to retry.** It means the local side may simply not exist yet (the
> checkout hasn't completed, or the membership row hasn't landed), so the
> WHOLE projection attempt -- including the just-claimed receipt -- is rolled
> back on purpose, and the SAME logical event must be redelivered later to
> succeed. Let it surface as a 5xx (or whatever your provider treats as
> "redeliver this event"); catching it and still returning 2xx silently
> discards the event, since nothing durable remembers the delivery happened.
> Every OTHER deterministic rejection (`missing_subject`, `invalid_subject`,
> `plan_scope_mismatch`, `subject_mismatch`) instead commits a `rejected`
> receipt and will fail identically on every retry -- see
> [docs/BRING_YOUR_OWN_PROVIDER.md](docs/BRING_YOUR_OWN_PROVIDER.md#receipts-and-rejection-semantics)
> for the complete table.

## Managed plan API

Plan management routes are permission gated with `auth` plus
`subscriptions_plans_manage`, which calls `PermissionManager::can()` directly
for `subscriptions.plans.manage` on `subscriptions.plans` and fails closed.

```text
GET    /subscriptions/plans
POST   /subscriptions/plans
POST   /subscriptions/plans/import-config
GET    /subscriptions/plans/{key}
PATCH  /subscriptions/plans/{key}
POST   /subscriptions/plans/{key}/archive
```

`{key}` accepts lowercase letters, numbers, dot, underscore, and hyphen. The
reserved key `import-config` is rejected for plans so the collection import
route cannot collide with a plan key.

## CLI

```bash
php glueful subscriptions:show --tenant=<uuid>
php glueful subscriptions:set-plan --tenant=<uuid> --plan=pro
php glueful subscriptions:reconcile [--tenant=<uuid>]
php glueful subscriptions:plans:create --key=pro --name="Pro" --entitlements='{"reports.export":true}'
php glueful subscriptions:plans:update --key=pro --status=archived
php glueful subscriptions:plans:archive --key=pro
php glueful subscriptions:plans:import-config [--force]
php glueful subscriptions:plans:list
```

`subscriptions:plans:import-config` seeds the DB catalog from
`config/subscriptions.php`. Without `--force`, existing DB rows are left alone.
With `--force`, config entitlements and provider price-id links overwrite the
existing DB row.

Reconcile pulls the authoritative provider state through payvia's
`GatewaySubscriptionService::reconcile($gateway, $gatewaySubscriptionId)` and
applies status/period drift, appending a `reconciled` event (source
`reconcile`, NULL logical key). No drift means no write and no event.

Reconcile grants the **same dunning grace as the provider-event path**:
drifting into `past_due` sets `grace_ends_at = now + grace_days`, so a tenant
discovered late (for example, a missed webhook) is never downgraded instantly.
An already-`past_due` subscription never has its grace re-extended, and
settling back to `active` clears any grace.

Scheduling is **opt-in and default-off**:
`subscriptions.reconcile.schedule_enabled` is `false`. When you enable it, wire
`subscriptions:reconcile` into your scheduler (cron or the framework scheduler)
at the cadence you want; the package does not self-schedule.

## Soft-dependency behavior matrix

| Installed        | Checker               | Middleware                          | Tier bridge          | Lifecycle | Provider events | Reconcile      |
| ---------------- | --------------------- | ----------------------------------- | -------------------- | --------- | --------------- | -------------- |
| neither          | works (explicit uuid) | 403 unless permissive               | delegates to default | works     | none registered | no-op          |
| tenancy only     | works                 | full (current tenant resolves)      | active               | works     | none registered | no-op          |
| payvia only      | works (explicit uuid) | 403 unless permissive               | delegates to default | works     | projected       | pulls provider |
| tenancy + payvia | works                 | full                                | active               | works     | projected       | pulls provider |

"Works" for the checker always means: catalog + overrides + status gating; no
payment object is ever consulted at check time.

## Schema readiness

`SubscriptionSchemaReadiness::isReady(): bool` is the extension-owned
authority a host uses to distinguish "migrations haven't run yet" from
"ready" without inferring it from a migrations-ledger row. It probes the
live database directly for the complete minimum 2.x runtime shape -- every
table and column migration `006` introduces -- so a database that was
hand-rolled, partially migrated, or downgraded is caught the same as one
that was never migrated at all.

```php
use Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness;

$ready = app($context, SubscriptionSchemaReadiness::class)->isReady();
```

It never throws: any DB error while probing (a lost connection, a locked
database, an unsupported driver) resolves to `false`, never propagates --
a false positive would let a host surface broken admin APIs against a
partial schema, and a thrown exception would break a host's degraded-mode
fallback, so both are avoided. Registered shared/autowired by the service
provider.

## Testing

```bash
composer test    # phpunit (Unit + Integration)
composer analyze # phpstan
composer phpcs   # PSR-12
```

The suite runs entirely against an in-memory SQLite database by default -- no
external services required.

### Testing against PostgreSQL

`SubscriptionService::startFor()` isolates its insert in a nested transaction
(a real `SAVEPOINT`) so a lost race on the subject-unique constraint rolls
back only to that savepoint instead of poisoning the surrounding transaction
(spec §8). SQLite tolerates an in-transaction error without aborting the
session, so this failure mode can only be proven against a real PostgreSQL
server. `tests/Integration/Concurrency/PostgresSavepointTest.php` covers it,
gated behind three environment variables so it is a silent no-op (skipped)
everywhere a PostgreSQL server isn't configured:

| Variable                     | Example                                              |
| ----------------------------- | ---------------------------------------------------- |
| `SUBSCRIPTIONS_TEST_PG_DSN`  | `pgsql:host=127.0.0.1;port=5432;dbname=subscriptions_test` |
| `SUBSCRIPTIONS_TEST_PG_USER` | `subscriptions_test`                                 |
| `SUBSCRIPTIONS_TEST_PG_PASS` | `subscriptions_test` (an empty string is valid for trust-auth setups) |

All three must be set (even if `_PASS` is empty) or the test skips itself via
`markTestSkipped()`. When set, it opens a fresh `Connection` against that
database, drops and re-creates the extension's tables, runs migrations
001-006, then proves: a lost race on the SAME plan is idempotent, a lost race
on a DIFFERENT plan raises `SubscriptionConflictException`, and -- the reason
this suite exists -- a write issued in the outer transaction immediately
after the savepoint-isolated violation still commits.

Point it at any reachable PostgreSQL 13+ database, for example a local one:

```bash
createdb subscriptions_test
psql -c "CREATE ROLE subscriptions_test LOGIN PASSWORD 'subscriptions_test'"
psql -c "GRANT ALL ON DATABASE subscriptions_test TO subscriptions_test"

SUBSCRIPTIONS_TEST_PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=subscriptions_test" \
SUBSCRIPTIONS_TEST_PG_USER="subscriptions_test" \
SUBSCRIPTIONS_TEST_PG_PASS="subscriptions_test" \
vendor/bin/phpunit --filter PostgresSavepoint
```

To guarantee the proof actually executed (rather than silently skipped), add
`--fail-on-skipped` to the command above in any environment that provisions
PostgreSQL.

## Upgrading to 2.0

Release 1.4.0 is the upgrade bridge from 1.x to the 2.0 subject model. Subscriptions
2.0 introduces explicit `(tenant_uuid, subject_type, subject_uuid)` subject
identities (enabling workspace-scoped memberships), immutable `plan_uuid` references,
and provider-event receipt management. 1.4.0 is additive with no behavior changes.

**Upgrade sequence (maintenance window):**

1. Install and run the final 1.x release (1.4.0 or earlier):
   ```bash
   composer require "glueful/subscriptions:^1.4"
   php glueful migrate:run
   ```

2. Run the preparation command once (creates the required marker):
   ```bash
   php glueful subscriptions:prepare-v2
   ```
   This idempotent command imports configuration plans into the database and
   synthesizes archived empty-entitlement plans for any dangling subscription keys
   that exist in production. The command fails safely if any subscription cannot be
   resolved; no changes are written and can be re-run after fixing the underlying
   data.

3. Install 2.0 and migrate:
   ```bash
   composer require "glueful/subscriptions:^2.0"
   php glueful migrate:run
   ```

4. Import the plan catalog -- **required**, see [Install](#install):
   ```bash
   php glueful subscriptions:plans:import-config
   ```
   (`subscriptions:prepare-v2` in step 2 already imports the plans that existed
   at that point; re-run the import for anything added to config afterwards. An
   unimported catalog resolves every entitlement to an empty map.)

5. Review the override table if you write it directly. Post-`006`, inserts must
   supply `subject_type` + `subject_uuid` or the row is silently ignored -- see
   [Entitlement overrides](#entitlement-overrides).

6. (Optional) Enable memberships. 2.0 alone changes nothing about your existing
   tenant-facing **API** -- every 1.x call (`SubscriptionService::start()`,
   `current()`, `changePlan()`, ...) is preserved byte-for-byte as a facade over
   the new subject-aware core, and the default `SubjectResolverInterface`
   rejects every `user` subject. (Two integration-level changes do land for
   tenancy hosts -- table registration and system-mode entitlement reads; see
   [Note for tenancy hosts](#note-for-tenancy-hosts-new-in-20).) If you want the
   new workspace-membership product, bind your own `SubjectResolverInterface`
   (see [Enabling memberships](#enabling-memberships)) -- **binding the resolver
   is the enablement switch**; there is no config flag to flip.

The preparation marker protects 2.0 migration `006` from running without first
validating that all subscriptions can be resolved. No subscriptions are modified
by 1.4.0; all existing behavior is preserved until 2.0 migration completes.
