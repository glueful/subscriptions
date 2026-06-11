# Glueful Subscriptions

Tenant subscriptions, plans, stateless entitlements with numeric limits,
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

The catalog has two sources:

- Managed DB plans in `subscription_plans`.
- Config plans in `config/subscriptions.php` as seed/fallback.

Resolution prefers DB rows with status `active` or `archived`. If a DB row is
`draft`, it does not resolve and config is used when a config plan with the same
key exists. If neither source resolves, the entitlement map is empty and every
key denies.

An empty `subscription_plans` table is safe: config plans keep working. If a DB
is wiped, tenants on config-backed keys continue resolving from config; DB-only
plan keys resolve to an empty map until restored. If migration 004 has not run
yet, catalog reads catch the missing table and behave as config-only.

Plan assignment is stricter than plan resolution:

| Plan source/status | Resolves for existing tenants | Assignable to new tenants |
| ------------------ | ----------------------------- | ------------------------- |
| DB `active`        | yes                           | yes                       |
| DB `archived`      | yes                           | no                        |
| DB `draft`         | no                            | no                        |
| config only        | yes                           | yes                       |

Archived is never delete: tenants already on an archived plan keep resolving it.
Draft is pre-publish only; active and archived plans cannot transition back to
draft. An empty entitlement map `{}` is valid and means "deny every entitlement
key."

### Config seed (config/subscriptions.php)

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
$service->reconcile($tenantUuid);        // pull provider truth, when payvia is installed
```

Every transition appends a `subscription_events` row (`created`,
`plan_changed`, `canceled`, `reconciled`, or provider event types) with
`from_status` / `to_status` / `source` (`manual`, `payvia_event`,
`reconcile`).

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

Provider-event projection maps:

| Provider event          | Projection                                            |
| ----------------------- | ----------------------------------------------------- |
| `subscription.created`  | link provider sub, status `active`/`trialing`         |
| `subscription.updated`  | status/period drift; settling to active clears grace  |
| `subscription.past_due` | status `past_due`, `grace_ends_at = now + grace_days` |
| `subscription.canceled` | status `canceled`, `canceled_at`                      |
| `payment.succeeded`    | if `trialing`/`past_due` -> `active`, clear grace     |
| `invoice.paid`          | same settle path                                      |

Idempotency is claim-first: the `subscription_events` insert (unique per
`(payvia_gateway, payvia_logical_event_key)`) and the projection run in one
transaction, so a duplicate or concurrent delivery rolls back and never
re-projects -- grace can never be extended twice. The tenant mapping is
`(gateway, gateway_subscription_id)`; on `subscription.created` an unlinked row
can be recovered via provider metadata `tenant_uuid`.

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
With `--force`, config entitlements and Payvia priced-plan links overwrite the
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
