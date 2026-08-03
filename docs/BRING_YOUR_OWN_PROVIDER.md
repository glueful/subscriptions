# Bring Your Own Provider (BYOP)

`glueful/subscriptions` is provider-agnostic. All subscription-state projection
rules (idempotency, tenant relink, the status state machine, period/grace
handling) live in a generic projector that consumes a single DTO. The first-party
payment provider, **payvia**, is wired automatically when installed and needs
**zero** configuration. Any other payment package — or your own app code — can
drive subscription state by adapting its events into that same DTO, with no
payvia present and no subscriptions internals touched.

This guide shows how to plug in a custom provider:

1. [The contract](#1-the-contract) — the projector interface and the DTO.
2. [The normalized event vocabulary](#2-the-normalized-event-vocabulary) — the
   `type` values and `normalized` keys the projector understands.
3. [Registering your own bridge listener](#3-registering-your-own-bridge-listener)
   — adapt your provider's event and call the projector.
4. [The optional reconcile puller](#4-the-optional-reconcile-puller) — feed
   authoritative provider state into `reconcile()`.
5. [Worked example](#5-worked-example) — a compact end-to-end custom provider.
6. [Receipts and rejection semantics](#6-receipts-and-rejection-semantics) —
   the five outcomes, which commit and which roll back, and why your webhook
   endpoint's retry behavior depends on knowing the difference.

---

## 1. The contract

A provider integration touches exactly two types.

### `SubscriptionEventProjectorInterface`

```php
namespace Glueful\Extensions\Subscriptions\Contracts;

use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;

interface SubscriptionEventProjectorInterface
{
    public function project(ProviderSubscriptionEvent $event): void;
}
```

The default implementation, `Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector`,
is bound to this interface and is always available (it has no payvia dependency).
It owns **all** projection rules. You never reimplement them — you only hand it a
`ProviderSubscriptionEvent`.

`project()` is safe to call on every delivery: it claims each logical event once
(inside a single transaction with the state change), so duplicate or concurrent
deliveries are deduped and never double-project.

### `ProviderSubscriptionEvent` (the DTO)

```php
namespace Glueful\Extensions\Subscriptions\Projection;

final class ProviderSubscriptionEvent
{
    /** @param array<string,mixed> $normalized */
    public function __construct(
        public readonly string $gateway,          // e.g. 'stripe', 'paystack' (NOT 'payvia')
        public readonly string $type,             // normalized vocabulary, see below
        public readonly string $logicalEventKey,  // idempotency key (stable + unique per logical event)
        public readonly array  $normalized,       // gateway_subscription_id, status, current_period_end, metadata, ...
    ) {
    }
}
```

| Field | Meaning |
|---|---|
| `gateway` | The provider's gateway name (e.g. `stripe`, `paystack`). Used with `normalized['gateway_subscription_id']` to locate the subscription row. |
| `type` | One of the [normalized event types](#type-value). Unknown types are still recorded (claimed) but project nothing. |
| `logicalEventKey` | A stable, unique-per-logical-event idempotency key. The same logical event must always produce the same key; distinct events must produce distinct keys. |
| `normalized` | The [normalized payload](#normalized-keys) the projector reads (`gateway_subscription_id`, `status`, `current_period_end`, `metadata`). |

---

## 2. The normalized event vocabulary

The projector consumes this vocabulary directly. It is documentation, not an
enforced interface — your bridge simply produces matching values.

### `type` value

The projector handles exactly these `type` strings (from
`SubscriptionEventProjector::computeChanges()`):

| `type` | What it projects |
|---|---|
| `subscription.created` | Sets status to `trialing` if `normalized['status']` normalizes to `trialing`, otherwise `active`; applies `current_period_end` if present. **Never resurrects a `canceled` row** — a late/replayed create on a canceled subscription is recorded but projects nothing. |
| `subscription.updated` | Applies `normalized['status']` if it is a known status; when the new status is `active`, also clears `grace_ends_at`; applies `current_period_end` if present. |
| `subscription.past_due` | Sets status to `past_due` and sets `grace_ends_at` to now + the configured grace days. |
| `subscription.canceled` | Sets status to `canceled` and stamps `canceled_at`. |
| `payment.succeeded` | If the subscription is currently `trialing` or `past_due`, settles it to `active`, clears `grace_ends_at`, and applies `current_period_end`. Otherwise records the event with no state change. |
| `invoice.paid` | Identical handling to `payment.succeeded`. |

Any **other** `type` that still maps to an existing subscription is **recorded
(idempotency claim) with no projection** — it becomes a first-class deduped log
entry instead of being silently dropped. An event that does **not** map to any
subscription throws `UnmappedProviderSubscriptionException` instead — see
[§ metadata and subject validation](#metadata-and-subject-validation).

### `normalized` keys

All keys are optional except where noted (read by `mapToSubscription()`,
`normalizedStatus()`, and `periodChanges()`):

| Key | Required? | Notes |
|---|---|---|
| `gateway_subscription_id` | **Required to map** | Combined with `gateway` to find the subscription via `findByProviderSubscription`. Without it (and without a validated `metadata` recovery on a `subscription.created`), the event cannot be attached and `project()` throws `UnmappedProviderSubscriptionException`. |
| `status` | optional | Must be one of `active`, `trialing`, `past_due`, `canceled`, `incomplete`, `paused` (case-insensitive). Any other value is ignored. |
| `current_period_end` | optional | Any string `\DateTimeImmutable` can parse (datetime/timestamp). Unparseable values are ignored. |
| `metadata` | optional | An object/array. `tenant_uuid`/`subject_type`/`subject_uuid` are read for subject validation and cross-checks (see below); everything else is ignored. |

### `logicalEventKey` idempotency (receipts-first)

`logicalEventKey` (paired with `gateway`) is the dedupe key. The projector:

1. Does a cheap read-side early-out via `existsByLogicalKey` (checked against the
   provider-event **receipts** table) when both `gateway` and `logicalEventKey`
   are non-empty.
2. Opens ONE transaction and claims the event by inserting a `pending` row into
   `subscription_provider_event_receipts`, which is **unique on
   `(provider_gateway, provider_logical_event_key)`**. That claim — not the
   `subscription_events` insert — is the real idempotency gate. If a concurrent
   delivery already claimed it, the unique violation is swallowed (debug-logged)
   and the whole transaction rolls back.
3. Once claimed, resolution splits by **determinism**:
   - A deterministic validation failure (`missing_subject`, `invalid_subject`,
     `plan_scope_mismatch`, `subject_mismatch` — see below) settles the receipt
     `rejected` with that code and **commits**. Rejection is a diagnosable
     outcome, not an error — it will fail the exact same way on every retry.
   - An **unmapped** subscription is different: the local side may simply not
     exist YET, so `project()` throws `UnmappedProviderSubscriptionException`
     and the WHOLE transaction rolls back — pending receipt claim included.
     **Your webhook endpoint MUST let this exception surface as a
     retry-inducing response** (a 5xx, or whatever your provider treats as
     "redeliver this event"); catching it and still returning 2xx would
     acknowledge the webhook while silently discarding the event, since the
     receipt claim was just rolled back along with everything else.
   - Otherwise: `accepted`, alongside the `subscription_events` append and the
     state-machine write, all atomically.
   - Any OTHER genuine transient failure also rolls the whole transaction back
     (pending receipt included) and propagates, so the provider's retry of the
     same logical event can succeed later.

So the same logical event delivered twice (or concurrently) projects exactly
once. Give each distinct logical event a distinct key, and give retries of the
same event the same key — including retries driven by
`UnmappedProviderSubscriptionException` or a transient failure, both of which
leave the claim free to be retried.

### `metadata` and subject validation

`metadata` fields flow verbatim from the provider's webhook payload, so they are
**not a trust anchor** by themselves — the projector validates before trusting.

**On a `subscription.created` whose `(gateway, gateway_subscription_id)` is not
yet linked to any row**, `metadata` is the ONLY way to establish a new link:

- `metadata['tenant_uuid']` is required. `metadata['subject_type']` /
  `metadata['subject_uuid']` may be omitted (defaults to the tenant self-subject,
  the 1.x shape) but if `subject_type` is given, `subject_uuid` must be too.
  Missing/incomplete → **committed** rejection `missing_subject`.
- The resulting subject must pass `SubjectResolverInterface::validate()` (the
  shipped `DefaultSubjectResolver` rejects every **user** subject). Failing →
  **committed** rejection `invalid_subject`.
- The relink recovery below runs **only** for a validated **tenant** subject; a
  validated user subject has no recovery path here → **retryable**
  `UnmappedProviderSubscriptionException` (the local side may simply not have a
  membership row for this user yet).
- The target row's plan must actually be assignable to the resolved subject's
  scope (a tenant subject needs a platform plan). Mismatch → **committed**
  rejection `plan_scope_mismatch`.
- No existing row for that tenant at all → **retryable**
  `UnmappedProviderSubscriptionException` (the tenant's local subscription may
  not exist yet, e.g. checkout hasn't completed).

The relink itself will **never move an existing link**: if the target row is
already linked to a *different* provider subscription, the projector logs a
relink-conflict anomaly and throws `UnmappedProviderSubscriptionException`
(**retryable** — but the link is never moved, on this attempt or any retry, as
long as the conflict persists). It does not relink based on provider-echoed
metadata alone. (A server-issued correlation token is the proper long-term
mechanism, but that is an app-side concern, out of scope here.)

**On any event that already maps to a linked row** (found via
`gateway`/`gateway_subscription_id`), any subject fields present in `metadata`
are cross-checked against that row's stored subject triple — a mismatch is a
**committed** rejection (`subject_mismatch`) rather than being silently ignored.

---

## 3. Registering your own bridge listener

Your provider package dispatches its own event. You write a thin **bridge** that
adapts that event into a `ProviderSubscriptionEvent` and hands it to the injected
`SubscriptionEventProjectorInterface`. The bridge owns no projection rules.

This mirrors exactly what the first-party `PayviaSubscriptionEventBridge` does:

```php
namespace Acme\Billing\Bridge;

use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;

final class AcmeSubscriptionEventBridge
{
    public function __construct(
        private readonly SubscriptionEventProjectorInterface $projector
    ) {
    }

    public function __invoke(\Acme\Billing\Events\AcmeWebhookEvent $event): void
    {
        // Translate YOUR event shape into the normalized vocabulary (§2).
        $this->projector->project(new ProviderSubscriptionEvent(
            gateway:         'acme',
            type:            $event->mappedType(),          // -> a §2 type string
            logicalEventKey: $event->id(),                  // stable + unique
            normalized:      [
                'gateway_subscription_id' => $event->subscriptionId(),
                'status'                  => $event->status(),
                'current_period_end'      => $event->periodEnd(),
                // 'metadata' => ['tenant_uuid' => ...]  // recovery hint, optional
            ],
        ));
    }
}
```

Register the bridge as a shared, autowired service and attach it as a lazy
listener for your event class. This mirrors `SubscriptionsServiceProvider`'s
registration of the payvia bridge — a lazy `'@'.ServiceId` listener so the
projection pipeline is built on first dispatch, not at boot:

```php
// In your extension's ServiceProvider.

use Glueful\Events\EventService;

public static function services(): array
{
    return [
        AcmeSubscriptionEventBridge::class => [
            'class'    => AcmeSubscriptionEventBridge::class,
            'shared'   => true,
            'autowire' => true,
        ],
    ];
}

public function boot(\Glueful\Bootstrap\ApplicationContext $context): void
{
    app($context, EventService::class)->addListener(
        \Acme\Billing\Events\AcmeWebhookEvent::class,
        '@' . AcmeSubscriptionEventBridge::class   // lazy: resolved on first dispatch
    );
}
```

The projector is autowired into your bridge because subscriptions binds
`SubscriptionEventProjectorInterface` to its default implementation
unconditionally.

---

## 4. The optional reconcile puller

`SubscriptionService::reconcile()` reconciles drift by pulling the authoritative
state for a subscription from its provider. That pull goes through one optional
seam:

```php
namespace Glueful\Extensions\Subscriptions\Contracts;

interface ProviderStatePullerInterface
{
    /**
     * Pull authoritative provider state for a subscription.
     *
     * @return array<string,mixed>|null Normalized state, or null when unavailable.
     */
    public function pull(string $gateway, string $providerSubscriptionId): ?array;
}
```

The returned array uses the same `normalized` keys as §2 (`status`,
`current_period_end`); `reconcile()` diffs it against the stored row and applies
any drift (plus a `reconciled` event).

This seam is **optional**. If `ProviderStatePullerInterface` is left unbound,
`SubscriptionService` resolves a `null` puller and `reconcile()` is a safe no-op
that returns the current row unchanged. Bind your own puller to enable reconcile
for your provider:

```php
namespace Acme\Billing\Bridge;

use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;

final class AcmeProviderStatePuller implements ProviderStatePullerInterface
{
    public function pull(string $gateway, string $providerSubscriptionId): ?array
    {
        // Call your provider's API, return normalized state (or null on failure).
        return [
            'status'             => 'active',
            'current_period_end' => '2030-01-01 00:00:00',
        ];
    }
}
```

```php
// In your ServiceProvider's services():
ProviderStatePullerInterface::class => [
    'class'    => AcmeProviderStatePuller::class,
    'shared'   => true,
    'autowire' => true,
],
```

(When payvia is installed it binds `PayviaProviderStatePuller` to this interface
automatically; a third-party binding simply replaces it.)

---

## 5. Worked example

This end-to-end example drives subscription state through a custom `acme`
provider with **no payvia present** — it mirrors the shipped
`tests/Integration/Byop/CustomProviderExampleTest.php`. It builds the real
projector and a custom puller directly; in a real package these would be wired
as a bridge listener (§3) and a bound puller (§4).

```php
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\SubscriptionService;

// A subscription already linked to the acme provider, currently past_due.
// (provider_gateway = 'acme', provider_subscription_id = 'acme_1')

// --- Event projection: a successful payment settles past_due -> active ---
// Every inbound event is claimed as a `pending` provider-event receipt before
// projection runs (the receipts table's (gateway, logical_key) unique is the
// real idempotency gate), then settled to accepted or rejected -- see
// "Receipts and rejection semantics" (§6) below.
$projector = new SubscriptionEventProjector(
    new SubscriptionRepository(),
    new SubscriptionEventRepository(),
    new ProviderEventReceiptRepository(),
    PlanCatalog::fromContext($context),
    $context,
    new DefaultSubjectResolver(),
);

$projector->project(new ProviderSubscriptionEvent(
    gateway:         'acme',
    type:            'payment.succeeded',
    logicalEventKey: 'acme_1:paid:1',
    normalized:      [
        'gateway_subscription_id' => 'acme_1',
        'current_period_end'      => '2030-01-01 00:00:00',
    ],
));
// -> subscription status is now 'active' (settled from past_due)

// --- Reconcile: a custom puller feeds authoritative state ---
$puller = new class implements ProviderStatePullerInterface {
    public function pull(string $gateway, string $providerSubscriptionId): ?array
    {
        return ['status' => 'canceled'];
    }
};

$service = new SubscriptionService(
    new SubscriptionRepository(),
    new SubscriptionEventRepository(),
    PlanCatalog::fromContext($context),
    $context,
    $puller,
);

$service->reconcile('tenantA');
// -> subscription status is now 'canceled' (drift applied from the puller)
```

In normal application wiring you do **not** construct these by hand — you
register a bridge listener (§3) and bind a puller (§4) in your extension's
service provider, and let the container autowire the projector and service.

**Payvia needs none of this.** When `glueful/payvia` is installed,
`SubscriptionsServiceProvider` automatically registers `PayviaSubscriptionEventBridge`
as a listener for payvia's `PaymentProviderEvent` and binds
`PayviaProviderStatePuller` to `ProviderStatePullerInterface`. It is the
zero-glue first-party default; BYOP is only for everything else.

---

## 6. Receipts and rejection semantics

Every inbound provider event is claimed as a `pending` row in
`subscription_provider_event_receipts` before resolution begins — the
`(provider_gateway, provider_logical_event_key)` unique index on that table is
the real idempotency gate (see [§2's `logicalEventKey`
idempotency](#logicaleventkey-idempotency-receipts-first)). Once claimed,
`project()` settles into exactly one of five outcomes:

| # | Outcome | Trigger | Receipt `outcome` | Transaction | Retry behavior |
|---|---|---|---|---|---|
| 1 | `missing_subject` | A `subscription.created` event's metadata omits `tenant_uuid`, or gives `subject_type` without `subject_uuid` | `rejected` | **commits** | Deterministic — fails identically on every redelivery until the provider sends complete metadata. |
| 2 | `invalid_subject` | The resolved subject fails `SubjectResolverInterface::validate()` (e.g. any `user` subject under the shipped `DefaultSubjectResolver`, which rejects them all) | `rejected` | **commits** | Deterministic — fails until a host binds a resolver that can vouch for the subject. |
| 3 | `plan_scope_mismatch` | The target plan's `(audience, owner_tenant_uuid)` does not match the resolved subject's own scope | `rejected` | **commits** | Deterministic — fails until the plan/subject pairing is corrected on your side. |
| 4 | `subject_mismatch` | A later event's metadata names a subject that disagrees with the row's already-stored triple | `rejected` | **commits** | Deterministic — fails until the provider stops sending the conflicting metadata. |
| 5 | *(unmapped)* — `UnmappedProviderSubscriptionException` | No row exists yet for `(gateway, gateway_subscription_id)` and no relink recovery applies, **or** a validated tenant subject's relink target is already linked to a different provider subscription | *(none — the whole insert is undone)* | **rolls back entirely, including the just-claimed receipt** | **Retryable** — the local side may simply not exist yet (or a relink conflict may resolve itself); the SAME logical event can succeed once it does. |

Any *other* genuinely transient failure (a database error mid-write, for
example) behaves exactly like outcome 5: the whole transaction — receipt claim
included — rolls back, so the provider's retry of the same logical event can
succeed later.

**The distinction that matters for your integration:**

- Outcomes 1–4 are **diagnosable, permanent-until-fixed** rejections. They
  commit a receipt row you can query and alert on (`outcome = 'rejected'`,
  `rejection_code` set to the exact code above); redelivering the identical
  event will not help, because nothing about the event itself changes between
  attempts.
- Outcome 5 is the opposite: nothing durable remembers the delivery happened
  — the receipt claim was rolled back along with everything else — so **your
  webhook endpoint MUST let `UnmappedProviderSubscriptionException` propagate
  as a retry-inducing response** (a 5xx, or whatever your provider's
  redelivery trigger is). Catching it and still returning 2xx acknowledges the
  webhook while silently discarding the event.

**Payvia's strict lane and equivalent guarantees:**

Payvia 2.4.0+ ships a `StrictPayviaSubscriptionEventBridge` that implements the
strict payment event lane, delivering ownership-scoped events at-most-once through
a compiled-container tag check with degradation to fault-isolated bus delivery when
the tag is stale. If you are building a custom provider (BYOP), you must provide
an equivalent guarantee: ensure that outcomes 1–4 commit (so they are never
retried) and outcome 5 (unmapped) rolls back the entire receipt claim, leaving it
free for retry. A bridge that logs an unmapped event but returns 2xx silently
discards it — the projector contract depends on that distinction for correctness.

See [§2's `metadata` and subject
validation](#metadata-and-subject-validation) for exactly which check produces
which code.
