# Subscriptions — Bring-Your-Own-Provider (BYOP) Design

- **Date:** 2026-06-13
- **Status:** Approved (pending spec review)
- **Extension:** `glueful/subscriptions` (currently 1.1.1, **not yet publicly installed**)
- **Related:** `glueful/payvia` (the first-party payment provider; stays unchanged by this work)

## 1. Problem & Goal

Today the subscriptions extension is structurally coupled to payvia in two places:

1. **Event projection** — `PaymentProviderEventListener` both *adapts* payvia's event shape and *owns* all the projection rules (idempotency, tenant relink, status state machine), and it is registered only for payvia's concrete `\Glueful\Extensions\Payvia\Events\PaymentProviderEvent` class.
2. **Reconcile** — `SubscriptionService::reconcile()` falls back to calling payvia's `GatewaySubscriptionService` directly via a `class_exists` branch.

Additionally, subscriptions-owned domain vocabulary (DB columns, service options, config keys, CLI flags) is named `payvia_*`, baking one provider's name into the generic concept of "the external payment provider."

**Goal:** make subscriptions provider-agnostic. A third-party payment package (or app) must be able to drive subscription state with **zero payvia present and zero subscriptions internals touched**, while payvia continues to work as the zero-glue first-party default. Because the extension is not public yet, we take the clean path: no backward-compat shims, no dual-read columns, no aliases.

## 2. Non-Goals (explicitly deferred)

- **Central config-driven event-class registry.** Rejected as the primary path: it is less explicit than provider-owned bridge listeners and re-invites duck-typing through config. Third-party providers register their own bridge listener instead.
- **A subscriptions-owned event *interface* that third-party provider events must implement.** Rejected: it reverses dependency pressure (providers would depend on subscriptions). The bridge adapts external events *into* the subscriptions DTO instead.
- **Backward-compat / migration shims.** Not public → one clean schema rename in the base migrations, all code/tests updated. No dual-read, no old-column aliases, no compatibility layer.
- **Server-issued provider correlation token** for tenant binding. Documented as future hardening (§10). v1 keeps treating `metadata.tenant_uuid` as a *recovery hint only* (already hardened: relink only attaches an unlinked row, never moves an existing link).

## 3. Architecture

Three layers, with payvia isolated to a single thin adapter.

```
 provider webhook/event                         reconcile (cron/CLI)
          │                                              │
  ┌───────▼────────────┐                         ┌───────▼─────────────┐
  │ PayviaSubscription │  (first-party bridge,   │ PayviaProviderState │  (first-party puller,
  │ EventBridge        │   only when payvia      │ Puller              │   only when payvia
  │  · adapts shape    │   installed)            │  · wraps payvia svc │   installed)
  └───────┬────────────┘                         └───────┬─────────────┘
          │ ProviderSubscriptionEvent (DTO)              │ ?array provider state
  ┌───────▼───────────────────────────┐         ┌────────▼────────────────────────┐
  │ SubscriptionEventProjector         │         │ SubscriptionService::reconcile() │
  │ (owns ALL projection rules:        │         │ depends on                       │
  │  idempotency claim, relink guard,  │         │ ProviderStatePullerInterface     │
  │  status state machine, period/grace)│        └──────────────────────────────────┘
  └────────────────────────────────────┘
          │
   subscription state (provider_* columns)
```

Third-party providers register their **own** bridge listener (in their package's service provider or app code) that builds a `ProviderSubscriptionEvent` and calls the injected `SubscriptionEventProjectorInterface`. They likewise bind their own `ProviderStatePullerInterface`. No payvia, no subscriptions internals.

## 4. Components

### 4.1 `ProviderSubscriptionEvent` (DTO) — generic input boundary
Namespace: `Glueful\Extensions\Subscriptions\Projection`

```php
final class ProviderSubscriptionEvent
{
    /** @param array<string,mixed> $normalized */
    public function __construct(
        public readonly string $gateway,          // e.g. 'stripe', 'paystack' (NOT 'payvia')
        public readonly string $type,             // normalized vocabulary, see §6
        public readonly string $logicalEventKey,  // idempotency key (unique per logical event)
        public readonly array  $normalized,       // gateway_subscription_id, status, current_period_end, metadata, ...
    ) {}
}
```

### 4.2 `SubscriptionEventProjectorInterface` + `SubscriptionEventProjector`
Interface namespace: `Glueful\Extensions\Subscriptions\Contracts`
Impl namespace: `Glueful\Extensions\Subscriptions\Projection`

```php
interface SubscriptionEventProjectorInterface
{
    public function project(ProviderSubscriptionEvent $event): void;
}
```

`SubscriptionEventProjector` is the new home for **all** logic currently in `PaymentProviderEventListener`:
- the cheap `existsByLogicalKey` early-out;
- the claim-first transactional idempotency (`insertOrThrow` on the unique `(provider_gateway, provider_logical_event_key)` index inside the same transaction as the projection; unique-violation → swallow with debug log);
- `mapToSubscription` including the **relink-conflict guard** (only attach an unlinked row; never move an existing link; warn + no-op on mismatch) and the metadata-tenant_uuid "recovery hint" doc;
- `computeChanges` including the **canceled-resurrection guard** (a `subscription.created` never reactivates a `canceled` row);
- `periodChanges` / grace handling / `formatForDb`;
- the defensive logger resolution.

Constructor deps: `SubscriptionRepository`, `SubscriptionEventRepository`, `PlanCatalog`, `ApplicationContext` (same set the listener has today). The recorded event's `source` becomes the generic `'provider_event'` (set by the projector, not the adapter).

**Intentional behavior change — unknown event types now claim.** Today the listener returns *before* the idempotency claim when `computeChanges` yields "type not handled." The projector changes this: if a provider event **maps to an existing subscription** but has an unknown `type`, the projector records the idempotency claim with **no projection** (empty change set). This makes unknown-but-mapped events first-class deduped/recorded entries instead of silently dropping them. Events that do not map to any subscription remain a no-op (nothing to record against a tenant).

### 4.3 `PayviaSubscriptionEventBridge` (thin first-party adapter)
Namespace: `Glueful\Extensions\Subscriptions\Bridge`

Replaces `PaymentProviderEventListener`. Owns **no** projection rules.

```php
final class PayviaSubscriptionEventBridge
{
    public function __construct(private readonly SubscriptionEventProjectorInterface $projector) {}

    public function __invoke(object $payviaEvent): void
    {
        $inner = $payviaEvent->event ?? null;       // payvia wrapper → inner PaymentProviderEventInterface
        if (!is_object($inner)) {
            return;
        }
        $this->projector->project(new ProviderSubscriptionEvent(
            gateway:         (string) $inner->gateway(),
            type:            (string) $inner->type(),
            logicalEventKey: (string) $inner->logicalEventKey(),
            normalized:      (array)  $inner->normalized(),
        ));
    }
}
```

This is the **only** subscriptions file permitted to name a payvia type, and it is registered only when `class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class)`.

### 4.4 `ProviderStatePullerInterface` + `PayviaProviderStatePuller` (reconcile seam)
Interface namespace: `Glueful\Extensions\Subscriptions\Contracts`
Impl namespace: `Glueful\Extensions\Subscriptions\Bridge`

```php
interface ProviderStatePullerInterface
{
    /** @return array<string,mixed>|null Normalized provider state, or null when unavailable. */
    public function pull(string $gateway, string $providerSubscriptionId): ?array;
}
```

`SubscriptionService` depends on `?ProviderStatePullerInterface` (replacing the current `?callable $providerStatePuller` and the inline `class_exists(Payvia...)` branch in `pullProviderState`). When null/unavailable, `reconcile()` is a safe no-op returning the current row (unchanged behavior). `PayviaProviderStatePuller` wraps payvia's `GatewaySubscriptionService` and is bound to the interface only when payvia exists; an app rebinds the interface to its own puller.

**Optional-puller construction.** `SubscriptionService` is factory-created today; keep that pattern. The factory resolves the puller explicitly rather than autowiring a nullable unbound interface (an unbound interface must not be autowired):

```php
$puller = $c->has(ProviderStatePullerInterface::class)
    ? $c->get(ProviderStatePullerInterface::class)
    : null;
```

### 4.5 Wiring (`SubscriptionsServiceProvider`)
- Register `SubscriptionEventProjector` as the shared `SubscriptionEventProjectorInterface` binding (payvia-agnostic, always available).
- Register `PayviaSubscriptionEventBridge` and its event listener **only** inside the existing `class_exists(payvia event)` guard, lazily (`'@'.PayviaSubscriptionEventBridge::class`) for `PaymentProviderEvent::class`.
- Bind `ProviderStatePullerInterface` → `PayviaProviderStatePuller` only when payvia's `GatewaySubscriptionService` class exists; otherwise leave unbound (null) so reconcile no-ops.
- Keep all registration inside the boot-resilience try/catch guards added previously.

## 5. The `provider_*` rename

One clean rename across all subscriptions-owned surfaces. **DB columns are renamed in the base migrations directly** (no new migration, no shim) because there are no public installs.

### 5.1 Identifier mapping

| Old (`payvia_*`) | New (`provider_*`) | Notes |
|---|---|---|
| `payvia_gateway` | `provider_gateway` | columns in `subscriptions` (001) + `subscription_events` (003); all repo/service/event refs |
| `payvia_customer_id` | `provider_customer_id` | `subscriptions` (001) |
| `payvia_subscription_id` | `provider_subscription_id` | `subscriptions` (001) |
| `payvia_priced_plan_uuid` | `provider_price_id` | `subscriptions` (001), `subscription_plans` (004); generalized to a provider price/plan identifier **VARCHAR(191)** (was 12-char). See §5.3. |
| `payvia_logical_event_key` | `provider_logical_event_key` | `subscription_events` (003) |
| `payvia_priced_plan` (config key) | `provider_price_id` | plan-catalog config key in `config/subscriptions.php` + `PlanCatalog`/`PlanPayloadValidator` fallbacks |
| `'payvia_event'` (`source` value) | `'provider_event'` | written by the projector for all provider-event ingestion |
| index `uniq_subscriptions_payvia_sub` | `uniq_subscriptions_provider_sub` | `subscriptions` unique on `(provider_gateway, provider_subscription_id)` |

### 5.2 Method / option / CLI renames (subscriptions-owned)

| Old | New |
|---|---|
| `SubscriptionRepository::findByPayviaSubscription()` | `findByProviderSubscription()` |
| `SubscriptionRepository::allWithPayvia()` | `allWithProvider()` |
| `PlanCatalog::pricedPlanUuid()` | `providerPriceId()` |
| `PlanCatalog::pricedPlanUuid` config read of `payvia_priced_plan*` | reads `provider_price_id` |
| `PlanPayloadValidator::validatePayviaPricedPlanUuid()` | `validateProviderPriceId()` |
| `SubscriptionService::start()` opts `payvia_*` | `provider_*` (incl. `provider_price_id`) |
| Plan create/update payload field `payvia_priced_plan_uuid` | `provider_price_id` (routes docblocks + validator) |
| CLI `--payvia-priced-plan` (Create/UpdatePlanCommand) | `--provider-price-id` |
| `ListPlansCommand` column header `Payvia` | `Provider` |
| `PaymentProviderEventListener` (class + test) | replaced by `SubscriptionEventProjector` (+ `PayviaSubscriptionEventBridge`) |

### 5.3 `provider_price_id` generalization
`provider_price_id` is the single replacement for `payvia_priced_plan_uuid` across **every** surface — the DB columns (`subscriptions` 001, `subscription_plans` 004), the plan-catalog config key, the plan create/update payload field, the validator, `PlanCatalog`/`SubscriptionService` options, and the CLI flag. **`provider_priced_plan_uuid` is used nowhere** — there is no intermediate "priced_plan_uuid" naming.

The old column stored a 12-char NanoID referencing a payvia priced-plan row. Generalize to `provider_price_id` **VARCHAR(191)**, holding whatever price/plan identifier the provider uses (Stripe `price_…`, Paystack plan code, payvia priced-plan UUID, etc.). Validation changes from "12-char NanoID" to "non-empty string ≤191" (nullable). Functionally additive; payvia values still fit.

### 5.4 Cutover acceptance rule (grep-based)
After implementation, `payvia` (case-insensitive) may appear **only** in:
- the payvia bridge/puller class files (`PayviaSubscriptionEventBridge`, `PayviaProviderStatePuller`) and their soft-dep registration in `SubscriptionsServiceProvider`;
- comments / docs that specifically describe the Payvia adapter;
- payvia-specific adapter test fixtures (e.g. a fake that emulates payvia's `->event` wrapper shape).

Subscriptions **domain models, repositories, migrations, config, public method options, CLI flags, docs, and tests** must use `provider_*`. Reviewer check:

```
grep -rin payvia src/ config/ migrations/ routes.php tests/ docs/ \
  | grep -viE 'Bridge/Payvia|PayviaSubscriptionEventBridge|PayviaProviderStatePuller|class_exists\(.*Payvia|// .*Payvia|Payvia adapter|FakePayvia'
# → expected: no remaining matches
```

## 6. Normalized event vocabulary (the BYOP contract)

The projector consumes this vocabulary; payvia already produces it, and BYOP providers must map to it. This is documentation, not an enforced interface.

- **`type`** (one of): `subscription.created`, `subscription.updated`, `subscription.past_due`, `subscription.canceled`, `payment.succeeded`, `invoice.paid`. An unknown type that maps to an existing subscription is **recorded (idempotency claim) with no projection** — see the intentional behavior change in §4.2.
- **`normalized` keys** (all optional unless noted): `gateway_subscription_id` (required to map to a subscription), `status` (one of `active|trialing|past_due|canceled|incomplete|paused`), `current_period_end` (parseable datetime/timestamp), `metadata` (object; `metadata.tenant_uuid` used only as an *unlinked-row recovery hint*).
- **`logicalEventKey`** must be stable and unique per logical event for idempotency; **`gateway`** is the provider's gateway name.

## 7. Data flow

**Payvia path (first-party, zero glue):** payvia dispatches `PaymentProviderEvent` → `PayviaSubscriptionEventBridge` adapts `->event` into a `ProviderSubscriptionEvent` → `SubscriptionEventProjector::project()` runs the claim-first transaction and projects state.

**BYOP path:** provider package dispatches its own event → its own listener builds a `ProviderSubscriptionEvent` → calls the injected `SubscriptionEventProjectorInterface::project()`. Identical projection guarantees; no payvia loaded.

**Reconcile:** `SubscriptionService::reconcile()` → `ProviderStatePullerInterface::pull(gateway, providerSubscriptionId)` → drift diff → `updateByTenant` + `reconciled` event. Payvia binds `PayviaProviderStatePuller`; BYOP binds its own.

## 8. Backward compatibility

None required (not public). Payvia is untouched and continues to work via the bridge with byte-identical projection behavior. No dual-read, no aliases, no shim. The clean rename is the whole compatibility story.

## 9. Testing strategy

- **Projector tests** (`SubscriptionEventProjectorTest`): port every case from the current `PaymentProviderEventListenerTest` — idempotency/claim, relink-conflict no-op, canceled-resurrection guard, period/grace, unknown-type recording — retargeted at `SubscriptionEventProjector::project(ProviderSubscriptionEvent)`. Same assertions on stored state.
- **Payvia bridge test** (`PayviaSubscriptionEventBridgeTest`): a payvia-shaped fake event → asserts exactly one `project()` call with the correctly-mapped DTO (use a spy/fake projector).
- **BYOP example test**: a fake non-payvia provider event + a hand-written bridge + a fake `ProviderStatePullerInterface`, proving subscription state updates with **no payvia classes loaded** (assert via `class_exists` guard / no payvia autoload). Doubles as the docs example.
- **Reconcile test**: retarget `SubscriptionReconcileTest` onto `ProviderStatePullerInterface` (fake puller), plus the payvia-puller wiring.
- **Rename coverage**: update all tests to `provider_*`; the §5.4 grep is part of the acceptance check. Full suite must stay green (currently 200 tests).

## 10. Future work (documented, not in this spec)

- **Server-issued provider correlation token** for tenant binding: replace the `metadata.tenant_uuid` recovery hint with a token the app issues at checkout/session creation and the provider echoes back, removing reliance on provider-echoed metadata entirely. Requires designing the checkout/session-creation flow end-to-end.
- **Optional config-driven bridge registration** for payvia-shaped events from providers that don't ship a service provider — only if real demand appears; explicitly avoided here to prevent duck-typing-via-config.

## 11. Deliverables checklist

- `ProviderSubscriptionEvent` DTO
- `SubscriptionEventProjectorInterface` + `SubscriptionEventProjector` (projection logic moved out of the listener)
- `PayviaSubscriptionEventBridge` (thin adapter; replaces `PaymentProviderEventListener`)
- `ProviderStatePullerInterface` + `PayviaProviderStatePuller`; `SubscriptionService::reconcile()` depends on the interface
- `provider_*` rename across migrations, repositories, services, catalog, validator, controller/routes, CLI, config, tests (per §5)
- `docs/BRING_YOUR_OWN_PROVIDER.md` + worked example test
- CHANGELOG `## Unreleased` entries; cutover grep clean; full suite green
