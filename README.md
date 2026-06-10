# Glueful Subscriptions

Tenant subscriptions, plans, entitlements, quotas, trials, overrides, and
lifecycle sync for Glueful SaaS apps.

Subscriptions is a config-driven subscription lifecycle and entitlement
resolution layer. A tenant's effective entitlement map is resolved from a plan
catalog (config) plus per-tenant overrides (DB), gated by the subscription's
status -- never from a live payment object.

## Install

```bash
composer require glueful/subscriptions
php glueful extensions:enable subscriptions
php glueful migrate:run
```

Requires `glueful/framework ^1.54.0` (the release that ships the
`Glueful\Entitlements` seam and the container-precedence fix).

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

Value semantics (S3): an **absent** key denies (`allows` false, `limit` 0);
`false`/`0` deny; `true` and explicit `null` allow with **unlimited** limit;
a positive int allows with that limit.

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

## Plan catalog (config/subscriptions.php)

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
            'payvia_priced_plan' => null, // optional payvia billing-plan uuid
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
Per-tenant overrides (the `subscription_overrides` table) win per key and may
carry an expiry.

## Rate-limit tier bridge

`EntitlementTierResolver` implements the framework's `TierResolverInterface`
over the default resolver: plans grant boolean `rate.tier.{tier}` entitlement
flags for the tiers listed in `subscriptions.rate_tiers` (highest-first); the
first granted tier wins, and `TierManager` config owns the numbers. No tenant
or no granted flag delegates to the default resolver -- the bridge is inert
without tenancy.

## Consumes Payvia (when installed)

Subscriptions *consumes* payvia; payvia stays tenancy-agnostic:

- **Priced plans:** a catalog plan may point at a payvia billing plan via
  `payvia_priced_plan`.
- **Provider events:** when `Glueful\Extensions\Payvia\Events\PaymentProviderEvent`
  exists, a listener self-registers in `boot()` and projects normalized provider
  events (`subscription.created/updated/past_due/canceled`, `payment.succeeded`,
  `invoice.paid`) onto subscription status -- claim-first in one transaction with
  per-gateway logical-event-key dedupe DB-enforced by a unique index, so a
  duplicate or concurrent delivery never re-projects (grace is never extended
  twice).
- **Reconcile:** `subscriptions:reconcile` pulls authoritative provider state
  through payvia's `GatewaySubscriptionService::reconcile($gateway, $gatewaySubscriptionId)`
  and applies drift, recording a `reconciled` event.

## CLI

```bash
php glueful subscriptions:show --tenant=<uuid>
php glueful subscriptions:set-plan --tenant=<uuid> --plan=pro
php glueful subscriptions:reconcile [--tenant=<uuid>]
```

See `docs/USAGE.md` for the full semantics tables, lifecycle API, and the
soft-dependency behavior matrix.
