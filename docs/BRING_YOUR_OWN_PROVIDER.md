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
subscription is a graceful no-op (there is nothing to record it against).

### `normalized` keys

All keys are optional except where noted (read by `mapToSubscription()`,
`normalizedStatus()`, and `periodChanges()`):

| Key | Required? | Notes |
|---|---|---|
| `gateway_subscription_id` | **Required to map** | Combined with `gateway` to find the subscription via `findByProviderSubscription`. Without it (and without a `metadata.tenant_uuid` recovery on a `subscription.created`), the event cannot be attached and the projector no-ops. |
| `status` | optional | Must be one of `active`, `trialing`, `past_due`, `canceled`, `incomplete`, `paused` (case-insensitive). Any other value is ignored. |
| `current_period_end` | optional | Any string `\DateTimeImmutable` can parse (datetime/timestamp). Unparseable values are ignored. |
| `metadata` | optional | An object/array. Only `metadata['tenant_uuid']` is read, and only as a **recovery hint** (see below). |

### `logicalEventKey` idempotency

`logicalEventKey` (paired with `gateway`) is the dedupe key. The projector:

1. Does a cheap read-side early-out via `existsByLogicalKey` when both `gateway`
   and `logicalEventKey` are non-empty.
2. Claims the event inside the projection transaction by inserting a
   `subscription_events` row that is **unique on `(provider_gateway, provider_logical_event_key)`**.
   If a concurrent delivery already claimed it, the unique violation is swallowed
   (debug-logged) and the projection rolls back.

So the same logical event delivered twice (or concurrently) projects exactly
once. Give each distinct logical event a distinct key, and give retries of the
same event the same key.

### `metadata.tenant_uuid` is a recovery hint only

`metadata.tenant_uuid` flows verbatim from the provider's webhook payload, so it
is **not a trust anchor**. The projector uses it in exactly one narrow case: on a
`subscription.created` whose `(gateway, gateway_subscription_id)` is not yet
linked to any row, it may attach an **unlinked** subscription (identified by that
tenant UUID) to the provider — writing both `provider_gateway` and
`provider_subscription_id`.

It will **never move an existing link**: if the target row is already linked to a
*different* provider subscription, the projector logs a relink-conflict anomaly
and no-ops. It does not relink based on provider-echoed metadata. (A server-issued
correlation token is the proper long-term mechanism, but that is an app-side
concern, out of scope here.)

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
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;

// A subscription already linked to the acme provider, currently past_due.
// (provider_gateway = 'acme', provider_subscription_id = 'acme_1')

// --- Event projection: a successful payment settles past_due -> active ---
$projector = new SubscriptionEventProjector(
    new SubscriptionRepository(),
    new SubscriptionEventRepository(),
    PlanCatalog::fromContext($context),
    $context,
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
