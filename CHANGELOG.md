# Changelog

All notable changes to `glueful/subscriptions` are documented here.

## 2.0.0 -- 2026-08-02

Subscriptions 2.0 generalizes the workspace-only 1.x lifecycle engine to two
coexisting, never-crossing products: **workspace subscriptions** (unchanged
tenant billing) and **user memberships** (a user's paid relationship to a
single workspace). See the design spec
(`docs/superpowers/specs/2026-08-02-subscriptions-v2-subject-model-design.md`)
for the full rationale.

### Breaking changes

- **Subject-model schema (migration `006_SubjectModel.php`).** `subscriptions`,
  `subscription_overrides`, and `subscription_events` each gain
  `subject_type`/`subject_uuid`, backfilled to `('tenant', tenant_uuid)` for
  every existing row. `subscriptions.plan_uuid` becomes a **NOT NULL**
  reference to `subscription_plans.uuid` in the same migration -- a row whose
  `plan_key` cannot be resolved aborts the migration rather than admitting a
  partially upgraded catalog. `UNIQUE(tenant_uuid)` is replaced by
  `UNIQUE(tenant_uuid, subject_type, subject_uuid)` (one subscription row per
  subject); `subscription_overrides`' unique becomes
  `UNIQUE(tenant_uuid, subject_type, subject_uuid, entitlement)`. A new
  `subscription_provider_event_receipts` table becomes the first claim
  authority for inbound provider events (see "Projector rejection semantics"
  below); every pre-2.0 provider lifecycle event is backfilled into an
  `accepted` receipt during `006` so a post-upgrade replay of a pre-2.0 event
  loses at the new claim authority instead of the legacy event-table backstop.
  A populated `subscriptions` table requires exactly one 1.4.0 preparation
  marker before `006` makes its first schema change; missing or duplicate
  state throws before any DDL runs.
- **DB-authoritative plan catalog -- config plans are seeds only.**
  `config('subscriptions.plans')` is no longer overlaid onto the database at
  resolve time; the database is the single authority for `PlanCatalog`. A new
  config plan added after upgrading to 2.0 has **no effect** until it is
  imported: run `subscriptions:plans:import-config` (create-missing, safe to
  re-run). There is no boot-time or request-time auto-import.
- **Scoped plan keys + immutable `plan_key` + `plan_uuid` references.**
  `subscription_plans` drops `UNIQUE(plan_key)` for
  `UNIQUE(audience, owner_tenant_uuid, plan_key)`, so a workspace's member
  plan named `pro` no longer collides with the platform `pro`. Subscriptions
  now reference plans by immutable `plan_uuid`; `plan_key` on the subscription
  row is a denormalized, immutable display/compat column refreshed on plan
  change, not a live foreign key. Any future plan-key rename is a separately
  designed operation -- an ordinary patch cannot do it.
- **Projector rejection semantics -- five outcomes, four committed + one
  retryable.** Every inbound provider event is claimed as a `pending` receipt
  before resolution. Four deterministic validation failures --
  `missing_subject`, `invalid_subject`, `plan_scope_mismatch`, and
  `subject_mismatch` -- commit a `rejected` receipt and write no lifecycle
  event; redelivering the identical event will not help. The fifth outcome,
  `UnmappedProviderSubscriptionException` (no local row yet, or a relink
  conflict), rolls back the **entire** transaction including the just-claimed
  receipt and is retryable once the local side catches up. **Webhook
  endpoints MUST let `UnmappedProviderSubscriptionException` propagate as a
  retry-inducing response** (5xx or your provider's redelivery trigger) --
  catching it and returning 2xx silently discards the event. See
  `docs/BRING_YOUR_OWN_PROVIDER.md` §6 for the full outcome table.
- **`subscription_events.data` is now sanitized.** All provider-sourced event
  data is normalized through `ProviderEventData::sanitize()` before storage,
  including **historical rows**, which migration `006` rewrites in place via
  `SubjectModel::sanitizeHistoricalProviderEventData()`. Code or tooling that
  read raw, unsanitized `data` payloads from `subscription_events` will see
  the sanitized shape after upgrading.
- **Stricter subject validation on read paths.** `SubscriptionService::current('')`
  and the other `...For()`/tenant-facade calls now throw
  `InvalidArgumentException('invalid subject')` on an empty or incoherent
  subject identity, where 1.x silently returned `null`. Callers that relied on
  a `null` result for a blank/invalid tenant UUID must catch the exception (or
  validate the UUID before calling).

### Upgrade instructions

1.x installations cannot migrate directly to 2.0 -- migrations receive only a
schema builder, not the application context needed to read the effective plan
config. Upgrade through the 1.4.0 bridge:

1. Put subscription writes into a maintenance window.
2. Install and run the final 1.x release, then the preparation command:
   ```bash
   composer require "glueful/subscriptions:^1.4"
   php glueful migrate:run
   php glueful subscriptions:prepare-v2
   ```
   This idempotent command imports configuration plans into the database,
   synthesizes archived empty-entitlement plans for any dangling subscription
   keys, and writes the preparation marker required by migration `006`. It
   fails safely (no writes) if any subscription cannot be resolved and can be
   re-run after fixing the underlying data.
3. Install 2.0 and migrate:
   ```bash
   composer require "glueful/subscriptions:^2.0"
   php glueful migrate:run
   ```
4. End the maintenance window. Every 1.x call (`current()`, `start()`,
   `changePlan()`, `cancel()`, `reconcile()`) is preserved as a facade over the
   new subject-aware core with unchanged signatures and behavior, and the
   default `SubjectResolverInterface` rejects every `user` subject, so 2.0
   alone changes nothing about existing tenant-facing behavior.
5. (Optional) Enable workspace memberships by binding your own
   `SubjectResolverInterface` that can vouch for real users -- **binding the
   resolver is the enablement switch**; there is no config flag. See
   [Enabling memberships](README.md#enabling-memberships).

Full details in [Upgrading to 2.0](README.md#upgrading-to-20).

### Added

- **Subject model.** A `Subject` value object
  (`Subject::tenant($tenantUuid)` / `Subject::user($tenantUuid, $userUuid)`)
  and `SubjectResolverInterface` (`currentTenant`/`currentUser`/`validate`)
  are the only place host identity knowledge enters the engine. The shipped
  `DefaultSubjectResolver` preserves 1.x tenant-self-subject behavior and
  rejects every `user` subject until a host binds its own resolver.
- **User memberships via resolver binding.** `SubscriptionService` gains a
  subject-aware core (`currentFor`/`startFor`/`changePlanFor`/`cancelFor`/
  `reconcileFor`) alongside the preserved 1.x tenant facade. `PlanManagementService`
  and `PlanPayloadValidator` become scope-aware
  (`audience`/`owner_tenant_uuid`) and enforce subject/plan-audience matching
  on every write.
- **Member entitlement resolution + middleware.** `MemberEntitlementResolver`
  resolves a user's membership in a workspace plus user-subject overrides,
  entirely separate from the unchanged tenant `EntitlementResolver`/
  `DefaultEntitlementChecker`. New `RequireMemberEntitlement` middleware
  (alias `require_member_entitlement`) resolves the current workspace and
  user via the subject resolver and fails closed (403) when either is
  missing, subject to the existing `permissive_middleware` escape hatch
  (which now governs both middlewares). `rate.tier.*` entitlements are
  stripped from member resolution -- rate tiers stay tenant-only.
- **Provider-event receipts.** `subscription_provider_event_receipts` records
  every inbound provider event -- candidate and resolved identity, outcome,
  and an allowlisted rejection code -- as a durable audit trail independent
  of the validated `subscription_events` lifecycle table.
  `ProviderEventReceiptRepository` is registered shared/autowired.
- **Subject data purger.** `SubscriptionSubjectDataPurger::purgeSubject(Subject $subject)`
  is a host-neutral purge primitive: a user-subject purge removes that user's
  subscription, overrides, events, and receipts (matched by resolved or
  candidate triple) while leaving the workspace's plans intact; a
  tenant-subject purge removes an entire workspace's subject rows plus its
  `audience='user'` plans, never touching platform plans. Both forms are
  idempotent and transactionally ordered. No purge runs automatically.
- **Tenant-table registration.** When the optional contracts package and a
  `TenantTableRegistry` binding are present, the provider registers
  `subscriptions`, `subscription_overrides`, and `subscription_events` for
  tenant query enforcement. `subscription_plans` and the provider-event
  receipts table are deliberately not registered (mixed platform/workspace
  ownership; candidates may have no valid tenant). Subject-scoped service
  work runs through `TenantContextRunner::runAsTenant(...)` when available;
  trusted provider projection, preparation, and purge operations that must
  discover or remove rows before a tenant context is known run through
  `runAsSystem(...)`.
- **Console subject/scope options.** `subscriptions:show`, `subscriptions:set-plan`,
  and `subscriptions:reconcile` gain `--subject-type`/`--subject-uuid` options
  for operating on memberships (tenant-oriented signatures and defaults
  unchanged). `subscriptions:plans:*` commands gain `--audience`/`--owner`,
  defaulting to the platform scope. New `subscriptions:plans:import-config`
  command performs the create-missing config import for fresh installs and
  later config-plan additions.
- **PostgreSQL CI.** `.github/workflows/ci.yml` provisions a PostgreSQL 16
  service and runs the full suite against it, with `--fail-on-skipped` on the
  savepoint proof so the job fails outright if the PostgreSQL-specific
  concurrency test (`PostgresSavepointTest`) is ever silently skipped instead
  of actually exercising the poisoned-transaction path
  `SubscriptionService::startFor()`'s savepoint isolation exists to prevent.
- `SubscriptionsServiceProvider::services()` registers `MemberEntitlementResolver`
  (non-shared factory -- its ctor-injected `PlanCatalog` is scoped to exactly
  one workspace, so a cached singleton would leak one tenant's entitlement
  scope into another's request), `ProviderEventReceiptRepository` and
  `SubscriptionSubjectDataPurger` (shared, autowired), and `RequireMemberEntitlement`
  under the new `require_member_entitlement` middleware alias.
  `SubjectResolverInterface` remains bound to `DefaultSubjectResolver`, shared
  and host-overridable -- binding a host resolver that can vouch for real users
  is what enables workspace memberships; there is no config flag for it.

### Documentation

- README: the two-layer product model (workspace subscriptions vs. user
  memberships, which never share a catalog or an entitlement map), the
  "Memberships" section (enabling the resolver, resolving member entitlements,
  the `require_member_entitlement` middleware, the provider metadata contract
  `tenant_uuid`/`subject_type`/`subject_uuid`/`plan_uuid`), and the retryable
  `UnmappedProviderSubscriptionException` webhook contract.
- `docs/BRING_YOUR_OWN_PROVIDER.md`: a new "Receipts and rejection semantics"
  section tabulating all five provider-event outcomes and which commit a
  `rejected` receipt versus which roll back the whole claim for a provider
  retry.
- `config/subscriptions.php`: comments only, no key changes -- documents that
  `plans` are seed-only (no runtime overlay), `rate_tiers` is tenant-only, and
  `permissive_middleware` now governs both route middlewares.

## 1.4.0 -- 2026-08-02

### Added

- Preparation-state migration (`005_CreateV2PreparationState.php`): creates the
  `subscription_v2_preparation` table to track upgrade bridge execution.
- `subscriptions:prepare-v2` console command: idempotent upgrade bridge that runs
  on the final 1.x installation before migrating to 2.0. Imports configuration
  plans into the database, synthesizes archived empty-entitlement plans for any
  dangling subscription keys, and writes a preparation marker (required by 2.0
  migration `006`). Details in the [Upgrading to 2.0](#upgrading-to-20) section.

### Changed

- None. Release 1.4.0 is additive with no behavior changes to existing 1.x APIs
  or lifecycle.

## 1.3.1 -- 2026-06-16

### Fixed

- Register migration paths during provider boot so `migrate:run` sees the
  subscriptions schema through the same CLI lifecycle used by other extension
  migrations.

## 1.3.0 -- 2026-06-14

### Changed

- Migrated OpenAPI documentation to the framework 1.57.0 reflect generator. Route
  documentation (summaries, query parameters, request-body fields and response codes)
  is now expressed as typed `#[ApiOperation]`, `#[QueryParam]` and `#[ApiResponse]`
  attributes on the controller methods; the now-inert route-file docblocks were removed.
  Docs-only — no runtime behaviour changes.
- Raised the minimum framework requirement to `^1.57.0`.

## 1.2.0 -- 2026-06-13

### Added

- Provider-agnostic subscription event projection. A new
  `SubscriptionEventProjectorInterface` + `ProviderSubscriptionEvent` DTO own all
  projection rules (claim-first idempotency, tenant relink, the status state
  machine, period/grace), and a `ProviderStatePullerInterface` drives reconcile.
  Third-party payment providers can now project subscription state and reconcile
  drift by mapping their events into the DTO and (optionally) binding their own
  puller -- with no payvia present and no subscriptions internals touched. See
  `docs/BRING_YOUR_OWN_PROVIDER.md`.

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
- Payvia is now a thin optional integration rather than the projection owner. The
  former `PaymentProviderEventListener` (which both adapted payvia's event shape
  AND owned the projection rules) is replaced by a generic
  `SubscriptionEventProjector` plus a thin `PayviaSubscriptionEventBridge`
  (event adapter) and `PayviaProviderStatePuller` (reconcile adapter), each
  registered only when payvia is installed. All subscriptions-owned `payvia_*`
  storage/options/config/CLI vocabulary is renamed to `provider_*`:
  `provider_gateway`, `provider_customer_id`, `provider_subscription_id`,
  `provider_price_id` (generalized from the 12-char `payvia_priced_plan_uuid` to
  a `VARCHAR(191)` provider price/plan identifier), and
  `provider_logical_event_key`; the event `source` value `payvia_event` becomes
  `provider_event`, the unique index `uniq_subscriptions_payvia_sub` becomes
  `uniq_subscriptions_provider_sub`, and the plan CLI flag `--payvia-priced-plan`
  becomes `--provider-price-id`. No backward-compat shim or dual-read (the
  extension is pre-release); the columns are renamed directly in the base
  migrations.
- An unknown provider event type that maps to an existing subscription is now
  recorded (the idempotency claim is taken) with no projected status change,
  instead of being dropped before the claim. This keeps every delivered event
  auditable and idempotent even when its type is not in the handled set.

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
