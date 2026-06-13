# Changelog

All notable changes to `glueful/subscriptions` are documented here.

## Unreleased

### Fixed

- Harden boot/registration against partial failures. Each independent
  registration step (migrations, command discovery, route loading, and the
  optional payvia event listener) is now wrapped in its own try/catch that logs
  a `[Subscriptions] ...` message and re-throws outside production -- so a single
  failing step degrades gracefully in production instead of aborting app boot,
  while still failing fast during development. The existing `registerMeta` guard
  is unchanged.
- Cap plan `description` at 255 characters in the payload validator (matching the
  `subscription_plans.description` `VARCHAR(255)` column) on both the create and
  patch paths. An over-long description now raises a clean validation error (HTTP
  422) instead of a confusing 500 (strict MySQL) or silent truncation. Only
  `description` is capped; other `nullableString` fields are unaffected.
- Fail closed when an entitlement value has an unrecognized type. Plan values are
  validated (`bool` | `int >= 0` | `null`) but override values are JSON-decoded
  and unvalidated, so a malformed value could reach the checker. `allows()` now
  denies (and `limit()` returns `0`) for any non-bool/non-numeric/non-null type --
  previously the JSON string `"false"` `(bool)`-coerced to a grant and an
  unrecognized type read as an unlimited (`null`) limit. The intentional
  `null = unlimited/allow` semantics are unchanged.
- Derive the entitlement cache key from the resolved content rather than from
  `updated_at` timestamps. The key now folds in a stable hash of the
  resolved-plan inputs (`status`, `plan_key`, `grace_ends_at`) and of the active
  override map, so a status/plan downgrade or an override edit invalidates the
  cache immediately even when a writer fails to bump `updated_at` -- closing a
  window (up to the cache TTL) in which a downgraded tenant kept elevated
  entitlements. The active override map is now read once and reused for both the
  key and the merge (no extra query).
- Only relink unlinked tenant subscriptions on provider `subscription.created`
  events. The provider-echoed `metadata.tenant_uuid` is now treated as a recovery
  HINT used solely to attach an as-yet-unlinked subscription -- never to move an
  existing link. A `subscription.created` naming a tenant whose row is already
  linked to a different provider subscription is logged as an anomaly
  (`subscriptions.relink_conflict_skipped`, no payload) and no-ops instead of
  silently stealing the link.
- Do not resurrect a canceled subscription on a late or replayed
  `subscription.created` event. When the stored status is already `canceled`, the
  event is still recorded/claimed but no status change is projected, so a
  delayed creation event can no longer flip a terminal subscription back to
  active.

### Changed

- Read plan write payloads (`store`/`update`) from the JSON body and POST form
  only -- query-string params are no longer merged into the body. Validation
  already gated every field, so this is a logging-hygiene change: plan fields
  (e.g. `entitlements`/`status`) passed via the query string for a write are no
  longer copied into the request body (and thus access logs). `importConfig`
  reads its `force`/`status` query params explicitly and is unaffected. Callers
  that relied on passing plan write fields via the query string must move them
  into the request body.

## 1.1.1 -- 2026-06-11

### Fixed

- Load plan catalog, entitlement resolver, and subscription service factories
  through the framework extension service DSL so the provider boots through the
  real `DefaultServicesLoader` in production. (Also keeps the provider compatible
  with the framework 1.55.0 load-time non-instantiable-binding guard.)
- Return denied entitlement and plan-management permission checks through the
  framework `Response` error envelope instead of raw/manual JSON responses.

### Changed

- Require `glueful/framework ^1.55.0` (was `^1.54.0`) as the security-hardened
  baseline. The entitlement seam and container-precedence fix this extension
  relies on shipped in 1.54.0; 1.55.0 adds the security/correctness hardening
  pass (permission-attribute enforcement, signed-URL fail-closed, fail-loud
  extension loading, etc.).

## 1.1.0 -- 2026-06-10

### Added

- Managed subscription plan catalog with DB-backed plans, config fallback, HTTP
  management API, and CLI commands.

### Changed

- Plan resolution now prefers active/archived DB plans over config plans while
  keeping config as seed/fallback.

## 1.0.0 -- 2026-06-10

Initial release.

### Added

- **Entitlement checker over the core seam:** `DefaultEntitlementChecker`
  implements framework-core `Glueful\Entitlements\Contracts\EntitlementCheckerInterface`
  and is bound over core's allow-all `NullEntitlementChecker` default (relies on
  the framework container-precedence fix; requires `glueful/framework ^1.54.0`).
  S3 value semantics: absent key denies; `false`/`0` deny; `true`/explicit
  `null` allow unlimited; positive int is the limit; non-positive ints deny
  with `limit() === 0` (`allows()` and `limit()` always agree).
- **Schema (3 tables at DEPENDENT priority):** `subscriptions` (one current
  subscription per tenant, unique `tenant_uuid`, nullable `payvia_*` link
  columns, unique `(payvia_gateway, payvia_subscription_id)`),
  `subscription_overrides` (per-tenant entitlement overrides with optional
  expiry, unique `(tenant_uuid, entitlement)`), and `subscription_events`
  (audit log with DB-enforced per-gateway logical-event-key dedupe via unique
  `(payvia_gateway, payvia_logical_event_key)` -- multiple all-NULL rows allowed
  for manual/reconcile events).
- **Config plan catalog** (`config/subscriptions.php`): `default_plan`, plans
  with entitlement maps, optional `payvia_priced_plan` links, `grace_days`,
  resolver cache settings, `permissive_middleware`, `rate_tiers`, and the
  opt-in reconcile scheduler flag.
- **Status-gated resolution with cache:** `EffectivePlanResolver` (lapsed /
  incomplete / paused / expired-grace tenants downgrade to `default_plan`;
  `past_due` keeps paid access only while `grace_ends_at` is in the future;
  trials resolve plan-as-trialed; `paused` is accepted from payvia's
  provider-status vocabulary and treated as not entitled to paid features)
  + `EntitlementResolver` (catalog + overrides merge) with a
  naturally-keyed cache (tenant + catalog fingerprint + row timestamps -- any
  change invalidates by key). `CacheStore` is optional; zero-infra installs
  resolve uncached.
- **`RequireEntitlement` route middleware**, fail-closed 403 with an
  `entitlement` error code; `permissive_middleware` opt-in allows requests with
  no tenant context. Registered under the `require_entitlement` alias
  (middleware-string form `require_entitlement:<entitlement>`).
  *The `#[RequireEntitlement]` route attribute is NOT shipped in v1* -- the
  framework has no generic attribute->middleware bridge for extension
  attributes (B1); the attribute form is deferred until a sanctioned hook
  exists.
- **`EntitlementTierResolver` rate-limit bridge** over the framework's default
  `TierResolver` (tier-flag mapping: boolean `rate.tier.{tier}` entitlements
  pick the bucket, `TierManager` config owns the numbers). Inert without
  tenancy; lookup failures degrade to the default resolver.
- **`SubscriptionService` lifecycle:** `current` / `start` / `changePlan` /
  `cancel` (at period end via metadata flag, or immediate) / `reconcile` --
  works fully with NO payvia installed (free/trial/comp). Every transition
  appends a `subscription_events` row.
- **Conditional payvia listener (S7):** when payvia's `PaymentProviderEvent`
  exists, `PaymentProviderEventListener` self-registers (lazy `@serviceId`)
  and projects normalized provider events onto subscription state --
  claim-first in ONE transaction (the event-row insert is the atomic gate), so
  duplicate/concurrent deliveries never re-project and `past_due` grace is set
  exactly once. A swallowed duplicate claim emits a debug-level log line
  (logger resolved defensively -- never a hard dependency) so a misclassified
  integrity error stays observable. Unmapped provider subscriptions no-op;
  `subscription.created` can recover the tenant link from provider metadata
  `tenant_uuid`.
- **Reconcile (soft payvia seam):** pulls authoritative state through payvia's
  `GatewaySubscriptionService::reconcile()` only when the class exists
  (injectable puller seam for tests); applies status/period drift and appends a
  `reconciled` event with a NULL logical key. Drifting into `past_due` grants
  the same dunning grace as the event path (`grace_ends_at = now + grace_days`;
  an already-past_due row is never re-extended), and settling to `active`
  clears grace.
- **CLI:** `subscriptions:reconcile [--tenant=]`, `subscriptions:show
  --tenant=`, `subscriptions:set-plan --tenant= --plan=` (validates the plan
  against the catalog).

### Tooling

- PHPStan at **level 6** via a committed `phpstan.neon` (`composer analyze` is
  config-driven); all array docblocks carry value types. PHPUnit suite and
  PSR-12 (`phpcs`) gates ship green.

### Guarantees

- Soft dependencies only: no payvia and no tenancy class is referenced without
  a `class_exists` guard; the package installs, boots, and passes its suite
  with neither package present.
- `tenant_uuid` is an opaque external id (no FK) -- works with any tenant
  source, not just `glueful/tenancy`.
- Entitlement checks are stateless reads (allow/deny + optional numeric
  limit); usage metering / quota consumption is a non-goal for v1 (roadmap:
  v1.1+).
