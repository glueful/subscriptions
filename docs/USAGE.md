# Subscriptions -- Usage Guide

## Entitlement checks in app code

Resolve the framework-core contract; this package's `DefaultEntitlementChecker`
is bound over it. The checker takes an explicit tenant uuid, so it works in
jobs, CLI, webhooks, and admin flows outside a request:

```php
use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;

$checker = app($context, EntitlementCheckerInterface::class);

$checker->allows($tenantUuid, 'reports.export');   // bool
$checker->limit($tenantUuid, 'projects.limit');    // ?int (null = unlimited)
```

### Value semantics (S3)

Entitlement values come from the plan catalog merged with active per-tenant
overrides (override wins per key; expired overrides are ignored):

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

### Status gating (S2 / S11)

The effective plan is derived from the subscription status before the map is
built:

| Subscription state                        | Effective plan       |
| ----------------------------------------- | -------------------- |
| none                                      | `default_plan`       |
| `active`                                  | the subscription's   |
| `trialing`                                | the trialed plan's   |
| `past_due`, `grace_ends_at` in the future | the subscription's   |
| `past_due`, grace passed or absent        | `default_plan`       |
| `incomplete`                              | `default_plan`       |
| `canceled`                                | `default_plan`       |

A lapsed tenant downgrades -- it is never locked out.

## Route middleware

```php
$router->post('/reports/export', [ReportController::class, 'export'])
    ->middleware(['require_entitlement:reports.export']);
```

- Tenant resolves + plan allows -> request passes through.
- Tenant resolves + plan denies -> `403` with error code `entitlement`.
- No tenant resolves -> `403` (fail closed) unless
  `subscriptions.permissive_middleware` is `true`, in which case the request
  passes (useful for single-tenant apps without tenancy installed).

The `#[RequireEntitlement]` attribute is NOT shipped in v1 (B1) -- use the
middleware-string form above.

## Lifecycle via SubscriptionService

```php
use Glueful\Extensions\Subscriptions\SubscriptionService;

$service = app($context, SubscriptionService::class);

// Free/comp/trial -- no payvia object needed, all payvia_* columns stay NULL.
$service->start($tenantUuid, 'free');
$service->start($tenantUuid, 'pro', [
    'status' => 'trialing',
    'trial_ends_at' => '2026-07-01 00:00:00',
]);

$service->current($tenantUuid);          // ?array (the subscriptions row)
$service->changePlan($tenantUuid, 'pro');
$service->cancel($tenantUuid);                       // at period end (metadata flag)
$service->cancel($tenantUuid, atPeriodEnd: false);   // immediate: status=canceled
$service->reconcile($tenantUuid);        // pull provider truth (see below)
```

Every transition appends a `subscription_events` row (`created`,
`plan_changed`, `canceled`, `reconciled`, or provider event types) with
`from_status` / `to_status` / `source` (`manual`, `payvia_event`,
`reconcile`).

## Reconcile + scheduler hook (S10)

```bash
php glueful subscriptions:reconcile                  # all payvia-linked subscriptions
php glueful subscriptions:reconcile --tenant=<uuid>  # one tenant
```

Reconcile pulls the authoritative provider state through payvia's
`GatewaySubscriptionService::reconcile($gateway, $gatewaySubscriptionId)` and
applies status/period drift, appending a `reconciled` event (source
`reconcile`, NULL logical key). No drift means no write and no event.

Reconcile grants the **same dunning grace as the provider-event path**:
drifting into `past_due` sets `grace_ends_at = now + grace_days`, so a tenant
discovered late (e.g. a missed webhook) is never downgraded instantly. An
already-`past_due` subscription never has its grace re-extended (the same
idempotency principle the listener enforces), and settling back to `active`
clears any grace.

Scheduling is **opt-in and default-off**: `subscriptions.reconcile.schedule_enabled`
is `false`. When you enable it, wire `subscriptions:reconcile` into your
scheduler (cron or the framework scheduler) at the cadence you want; the
package does not self-schedule.

## Provider-event projection (payvia installed)

The listener self-registers in `boot()` only when payvia's
`PaymentProviderEvent` class exists, and maps:

| Provider event           | Projection                                              |
| ------------------------ | ------------------------------------------------------- |
| `subscription.created`   | link provider sub, status `active`/`trialing`           |
| `subscription.updated`   | status/period drift (settling to active clears grace)   |
| `subscription.past_due`  | status `past_due`, `grace_ends_at = now + grace_days`   |
| `subscription.canceled`  | status `canceled`, `canceled_at`                        |
| `payment.succeeded`      | if `trialing`/`past_due` -> `active`, clear grace       |
| `invoice.paid`           | same settle path                                        |

Idempotency is claim-first: the `subscription_events` insert (unique per
`(payvia_gateway, payvia_logical_event_key)`) and the projection run in one
transaction, so a duplicate or concurrent delivery rolls back and never
re-projects -- grace can never be extended twice. The tenant mapping is
`(gateway, gateway_subscription_id)`; on `subscription.created` an unlinked
row can be recovered via provider metadata `tenant_uuid`.

## Soft-dependency behavior matrix

| Installed            | Checker | Middleware                            | Tier bridge        | Lifecycle | Provider events | Reconcile          |
| -------------------- | ------- | ------------------------------------- | ------------------ | --------- | --------------- | ------------------ |
| neither              | works (explicit uuid) | 403 unless permissive   | delegates to default | works   | none registered | no-op              |
| tenancy only         | works   | full (current tenant resolves)        | active             | works     | none registered | no-op              |
| payvia only          | works (explicit uuid) | 403 unless permissive   | delegates to default | works   | projected       | pulls provider     |
| tenancy + payvia     | works   | full                                  | active             | works     | projected       | pulls provider     |

"Works" for the checker always means: catalog + overrides + status gating; no
payment object is ever consulted at check time.
