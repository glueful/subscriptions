# Subscriptions 2.0 — Subject Model Design

**Status:** approved design, pre-implementation.
**Target release:** 2.0.0 (semver major; breaking schema + catalog contract changes with a preserved tenant facade).
**Baseline:** 1.3.1 (`glueful/subscriptions`, framework `>= 1.57.0`).

## 0. Purpose

1.x models exactly one product: a **tenant** subscribes to a **global** plan catalog.
2.0 generalizes the same lifecycle/payment engine to two coexisting products that
never share entitlements:

| Layer | Subscriber | Catalog | Controls |
|---|---|---|---|
| **Workspace subscription** (today's product) | a tenant/workspace | global platform catalog | workspace capabilities, limits, API rate tiers |
| **User membership** (new) | a user *within* one workspace | workspace-owned membership catalog | paywalls, member benefits, recurring content access |

Both layers run in the same installation at the same time. They are **separate
models, not configuration modes**: there is no per-install "subject mode".

The host (e.g. Thallo) decides what a "workspace" and a "user" are. The extension
never depends on any host implementation (no Thallo classes, no tenancy classes
beyond the existing soft probes).

## 1. Subject identity

Every subscription-domain row is identified by an explicit subject triple:

```
tenant_uuid   — the workspace scope (ALWAYS present, never a sentinel)
subject_type  — 'tenant' | 'user'
subject_uuid  — tenant UUID (subject_type=tenant) or global user UUID (subject_type=user)
```

Invariants (enforced by the subject validator, §4, and re-checked at projection):

- `subject_type = 'tenant'` ⇒ `subject_uuid === tenant_uuid`. A workspace
  subscribes to itself; there is no cross-workspace tenant subscription.
- `subject_type = 'user'` ⇒ `tenant_uuid` identifies the site selling the
  membership and `subject_uuid` the globally shared account. **Membership is
  always scoped by both.** A user's membership in workspace A grants nothing in
  workspace B.
- Single-site installs (host tenancy disabled) use the host's **real** stable
  default-workspace UUID as `tenant_uuid`. The extension does not know how the
  host derives it (Thallo: `SingleStoreTenant::resolve()` /
  `tenancy.default_tenant_uuid`); it only receives it through the resolver seam
  (§4). Sentinel values (`''`, `'default'`, …) are rejected by the default
  validator. This guarantees enabling tenancy later moves **zero rows**.

The triple applies uniformly across **subscriptions, overrides, events,
reconciliation, and provider projection** (§2, §6).

A `Subject` value object carries the triple through the codebase:

```php
final class Subject
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $type,      // SubjectType::TENANT | SubjectType::USER
        public readonly string $uuid,
    ) {}

    public static function tenant(string $tenantUuid): self;              // (t, 'tenant', t)
    public static function user(string $tenantUuid, string $userUuid): self;
}
```

## 2. Schema changes (migration `005_SubjectModel.php`)

All changes are additive-then-constrain, with in-migration backfill so every 1.x
row survives untouched in meaning.

### `subscriptions`
- **Add** `subject_type VARCHAR(10) NOT NULL DEFAULT 'tenant'`.
- **Add** `subject_uuid VARCHAR(64) NOT NULL DEFAULT ''`; backfill
  `subject_uuid = tenant_uuid` for all existing rows, then treat `''` as
  invalid at the application layer (no DB-level check for portability).
- **Add** `plan_uuid VARCHAR(12) NULL` (FK-by-convention to
  `subscription_plans.uuid`); backfilled in §3. After backfill the application
  treats `plan_uuid` as required on every write; `plan_key` remains as a
  denormalized display/compat column.
- **Drop** `UNIQUE (tenant_uuid)`.
- **Add** `UNIQUE (tenant_uuid, subject_type, subject_uuid)` —
  one subscription row per subject (§9). Existing rows satisfy this trivially.
- `UNIQUE (provider_gateway, provider_subscription_id)` is unchanged.

### `subscription_overrides`
- **Add** `subject_type` / `subject_uuid` with the same defaults + backfill.
- **Replace** `UNIQUE (tenant_uuid, entitlement)` with
  `UNIQUE (tenant_uuid, subject_type, subject_uuid, entitlement)`.
- Overrides therefore work identically for memberships (per-user, per-site
  entitlement grants/denials with optional expiry).

### `subscription_events`
- **Add** `subject_type` / `subject_uuid` with the same defaults + backfill.
- Dedupe key `UNIQUE (provider_gateway, provider_logical_event_key)` is
  unchanged — logical event keys are already globally unique per gateway.

### `subscription_plans`
- **Add** `audience VARCHAR(10) NOT NULL DEFAULT 'tenant'` — which subject type
  may hold this plan.
- **Add** `owner_tenant_uuid VARCHAR(64) NOT NULL DEFAULT ''` — `''` means
  *platform-owned* (the global catalog); a workspace UUID means a
  workspace-owned membership catalog entry. Note: `''` here is **catalog
  ownership**, a deliberate "platform" marker — it is not a subject identity
  and does not conflict with §1's no-sentinel rule (which governs
  `tenant_uuid`/`subject_uuid` on subscription rows). Invariant, enforced by
  the payload validator: `audience = 'tenant'` ⇒ `owner_tenant_uuid = ''`;
  `audience = 'user'` ⇒ `owner_tenant_uuid ≠ ''`.
- **Drop** `UNIQUE (plan_key)`; **add**
  `UNIQUE (audience, owner_tenant_uuid, plan_key)` — scoped key uniqueness. A
  workspace's member plan named `pro` never collides with the platform `pro`.
  (`owner_tenant_uuid` is NOT NULL specifically so this unique index behaves
  identically across MySQL/Postgres/SQLite — nullable columns in unique
  indexes do not.)
- `UNIQUE (uuid)` is unchanged and becomes the **subscription reference**:
  subscriptions point at `plan_uuid`, never at the mutable, scope-relative
  `plan_key` (§3).

## 3. Plan identity and catalog ownership

### DB-authoritative catalog
1.x overlays DB plans on top of `config('subscriptions.plans')` at resolve time.
2.0 makes the **database the single authority**:

- `config('subscriptions.plans')` becomes **seed data only**, imported at two
  moments and never at boot (no per-request DB write-checks): the `005`
  migration imports it once, and `subscriptions:plans:import-config` re-imports
  on demand (unchanged semantics: create-missing, never overwrite). Adding a
  new config plan after 2.0 therefore requires running the import command —
  config never shadows or overrides a DB row again.
- `PlanCatalog` reads one scope at a time:
  `PlanCatalog::for(audience, ownerTenantUuid)` with the platform catalog as
  `for('tenant', '')`. `entitlementsFor()`, `isAssignable()`,
  `providerPriceId()`, `version()` all operate within the selected scope.
  `version()` (the cache signature) incorporates the scope.
- `default_plan` remains a config key **for the platform catalog only** and is
  resolved key→uuid at runtime; memberships have no implicit default plan (no
  membership row ⇒ no membership).

### Immutable references
- Subscriptions store `plan_uuid`. `plan_key` on the subscription row is
  denormalized for display and for the 1.x facade, refreshed on plan change.
- Renaming a plan (changing `plan_key` or `display_name`) never touches
  subscription rows. Archiving a plan keeps it resolvable for existing
  subscribers (unchanged 1.x behavior, now via uuid).

### Backfill
Migration order inside `005`: (1) import config plans into `subscription_plans`
(platform scope) if missing; (2) backfill `subscriptions.plan_uuid` by joining
`plan_key` against the platform catalog; (3) rows whose `plan_key` matches no
plan (dangling config keys) get a plan row auto-created as
`status='archived'`, `entitlements={}` so the reference is never NULL — and the
migration logs each such synthesis loudly.

### Administrative authority
- **Platform plans** (`audience='tenant'`): managed by platform operators. The
  existing HTTP surface (`/subscriptions/plans`, permission
  `subscriptions.plans.manage`) continues to manage exactly this scope.
- **Workspace membership plans** (`audience='user'`): managed by workspace
  staff **through host-integrated surfaces** calling `PlanManagementService`
  with an explicit owner scope. The extension does not ship workspace-staff
  HTTP routes in 2.0 — authorization for "is this actor staff of that
  workspace" is host knowledge (Thallo's membership module provides the routes
  in Phase 3). `PlanManagementService` and `PlanPayloadValidator` become
  scope-aware and enforce the audience/owner invariants regardless of caller.

## 4. Host-provided subject validation

New contract (the **only** place host identity knowledge enters the engine):

```php
interface SubjectResolverInterface
{
    /** The current workspace scope, or null (fail closed downstream). */
    public function currentTenant(ApplicationContext $context): ?string;

    /** The current authenticated global user, or null. */
    public function currentUser(ApplicationContext $context): ?string;

    /** Existence + coherence: tenant exists; user exists; membership pairs allowed. */
    public function validate(ApplicationContext $context, Subject $subject): bool;
}
```

- **Default implementation** (no host binding): `currentTenant()` keeps 1.x
  behavior (`Glueful\Extensions\Tenancy\Context\TenantContext` when present,
  else the `tenancy.tenant` request-state seam, else null). `currentUser()`
  returns null. `validate()` accepts `subject_type='tenant'` iff
  `subject_uuid === tenant_uuid` and both are non-empty, and **rejects every
  `subject_type='user'` subject** — user subscriptions are inert until the
  host binds a resolver that can vouch for users. This is the capability
  switch: *binding the resolver is enabling memberships*.
- **Write-path enforcement**: `SubscriptionService` and
  `PlanManagementService` validate the subject (and plan-audience match:
  a `user` subject may only hold an `audience='user'` plan whose
  `owner_tenant_uuid` equals the subject's `tenant_uuid`; a `tenant` subject
  only an `audience='tenant'` platform plan) before any insert/update.
- **Webhook revalidation** (§6): projection re-validates the complete identity
  from provider metadata through the same contract before mutating state. A
  webhook naming a nonexistent user, a foreign workspace, or a mismatched
  triple is rejected and logged; the event row still records the attempt
  (append-only audit) with a `rejected` marker in its payload.

## 5. Entitlement resolution — two entry points, never crossed

- **Tenant resolver (existing, unchanged semantics):**
  `EntitlementResolver::resolveMap($context, $tenantUuid)` resolves the
  workspace's platform plan + tenant-subject overrides.
  `DefaultEntitlementChecker` (the framework `EntitlementCheckerInterface`
  binding) remains **tenant-backed only**. `RequireEntitlement`
  (`require_entitlement`) is unchanged.
- **Member resolver (new):**
  `MemberEntitlementResolver::resolveMap($context, $tenantUuid, $userUuid)`
  resolves the user's membership in that workspace + user-subject overrides.
  Ships with its own middleware `RequireMemberEntitlement`
  (alias `require_member_entitlement`): resolves the current workspace and the
  current user via the subject resolver, **fails closed** (403) when either is
  missing (subject to the existing `permissive_middleware` escape hatch, which
  applies to both middlewares).
- **Rate tiers are tenant-only** — pinned. `EntitlementTierResolver` continues
  to read the tenant resolver exclusively. A membership carrying a
  `rate.tier.*`-shaped entitlement has no effect on API rate limiting; the
  member resolver strips `rate.tier.*` keys from its output (defense in depth,
  logged once per resolve when stripped).
- Caching: both resolvers reuse the content-hash cache-key scheme; keys embed
  the full subject triple + the scoped catalog `version()`.

## 6. Provider integration (payvia and BYOP)

Ownership table — who owns which provider object, per subject type:

| Provider object | Workspace subscription | User membership |
|---|---|---|
| Customer | one per workspace (`tenant_uuid`) | one per **(workspace, user)** pair |
| Price | on the platform plan row (`provider_price_id`) | on the workspace's member plan row |
| Checkout/session creation | host/operator tooling (unchanged) | host storefront (Thallo Phase 3); never the extension |
| Webhook → state | projector | projector (same pipeline) |

- **Metadata contract:** every provider subscription created by a host MUST
  carry `tenant_uuid`, `subject_type`, `subject_uuid`, and `plan_uuid` in its
  metadata. The payvia bridge normalizes these into
  `ProviderSubscriptionEvent::$normalized`; the projector requires the
  complete triple for `subscription.created` and validates it (§4) — events
  without a resolvable, valid subject are rejected (not silently mapped to a
  tenant, as 1.x could).
- For post-creation events (`updated`, `past_due`, `canceled`,
  `payment.succeeded`, `invoice.paid`) the projector resolves the local row by
  `(provider_gateway, provider_subscription_id)` first (unchanged), then
  cross-checks any subject metadata present against the stored triple —
  mismatch ⇒ reject + log.
- `ProviderStatePullerInterface` is unchanged; `reconcile()` becomes
  subject-aware internally (it operates on a row, and the row now carries its
  subject).
- The browser return page never grants access; state changes only via
  projector/reconcile (unchanged 1.x posture, restated because Phase 3
  storefronts will be tempted).

## 7. Service API and compatibility facade

New subject-aware core:

```php
SubscriptionService::currentFor(Subject $s): ?array
SubscriptionService::startFor(Subject $s, string $planUuid, array $opts = []): array
SubscriptionService::changePlanFor(Subject $s, string $planUuid): array
SubscriptionService::cancelFor(Subject $s, bool $atPeriodEnd = true): array
SubscriptionService::reconcileFor(Subject $s): ?array
```

**Preserved tenant facade** (1.x signatures, delegating to the subject-aware
core with `Subject::tenant($tenantUuid)` and key→uuid catalog lookup):

```php
current(string $tenantUuid)                      // unchanged signature
start(string $tenantUuid, string $planKey, ...)  // unchanged signature
changePlan(string $tenantUuid, string $planKey)  // unchanged signature
cancel(string $tenantUuid, bool $atPeriodEnd)    // unchanged signature
reconcile(string $tenantUuid)                    // unchanged signature
```

The facade is not deprecated in 2.0 — it is the supported API for the
workspace-billing product (Thallo Phase 2 builds against it). Repositories
gain subject-aware finders (`findBySubject`, `updateBySubject`); the
tenant-named 1.x methods remain as facades with the same delegation rule.
Console commands (`subscriptions:show`, `subscriptions:set-plan`,
`subscriptions:reconcile`) keep their tenant-oriented signatures and gain
`--subject-type/--subject-uuid` options for memberships; `plans:*` commands
gain `--audience/--owner` (defaulting to the platform scope, preserving 1.x
behavior verbatim).

## 8. Concurrency and uniqueness

- **One subscription row per subject** — `UNIQUE (tenant_uuid, subject_type,
  subject_uuid)` is the source of truth. `startFor()` inserts inside a
  try/catch: a unique violation (the 1.x `isUniqueViolation()` helper,
  generalized) means a concurrent start won; the loser re-reads and applies
  its intent as `changePlanFor()` semantics or returns the winner's row,
  matching 1.x's tenant-insert race posture.
- One **active membership** per (workspace, user) in v1 of the model:
  concurrent memberships / add-on stacks are **explicitly deferred** — the
  single-row-per-subject constraint is the enforcement, and any future
  stacking design supersedes this spec deliberately, not accidentally.
- Projection dedupe by `(gateway, logical_event_key)` is unchanged and remains
  insert-first (`insertOrThrow` / `append`).

## 9. Configuration changes

```php
return [
    'default_plan' => 'free',          // platform catalog only (unchanged)
    'plans' => [...],                  // SEEDS for the platform catalog (no runtime overlay)
    'rate_tiers' => [...],             // unchanged; tenant resolver only
    'grace_days' => 3,                 // unchanged; applies per subscription row
    'cache' => [...],                  // unchanged
    'permissive_middleware' => false,  // now governs BOTH middlewares
    'reconcile' => ['schedule_enabled' => false],  // unchanged (documentation-only)
];
```

No new config keys. Memberships are enabled by **binding a
`SubjectResolverInterface`** that vouches for users (§4), not by config.

## 10. Testing strategy

Extends the existing phpunit suite (all new behavior unit-tested against the
repository fakes / in-memory DB the suite already uses):

1. **Migration/backfill:** a seeded 1.x dataset (config-only plans, DB plans,
   subscriptions, overrides, events) migrates to: platform plan rows for every
   config key, `plan_uuid` populated everywhere, subject columns backfilled as
   `('tenant', tenant_uuid)`, dangling-key synthesis logged, new uniques in
   place, old `UNIQUE(tenant_uuid)` gone.
2. **Facade equivalence:** every 1.x tenant call produces byte-identical rows
   to the subject-aware equivalents; a 1.x-shaped test copied from the current
   suite must pass unmodified against 2.0.
3. **Catalog scoping:** key collisions across scopes coexist (`platform pro`
   vs two different workspaces' `pro`); cross-scope resolution is impossible
   (a user subject cannot start a platform plan, a tenant subject cannot start
   a member plan, wrong-owner member plans rejected).
4. **Validator seams:** default resolver rejects all user subjects; a bound
   fake resolver enables them; sentinel/empty identities rejected; write-path
   and projection-path both enforce.
5. **Webhook revalidation:** created-event without complete triple rejected;
   mismatched subject metadata on later events rejected; both leave audit
   event rows; valid flows project as 1.x did.
6. **Resolver separation:** membership entitlements never appear in
   `EntitlementResolver`/`DefaultEntitlementChecker` output; `rate.tier.*`
   stripped from member resolution; tier resolver output unchanged by any
   membership fixture.
7. **Concurrency:** duplicate `startFor` race resolves via unique-violation
   path for both subject types.

## 11. Out of scope (deliberately)

- Per-workspace **platform** catalogs (platform plans stay global).
- Multiple concurrent memberships / add-on stacking (§8).
- Proration, invoicing, metering, dunning UI.
- Any checkout/storefront HTTP surface (host-owned; Thallo Phase 3).
- Workspace-staff plan-management HTTP routes (host-owned; Thallo Phase 3).
- Host UI of any kind.

## 12. Program context (for cross-repo readers)

This spec is Phase 1 of a three-phase program agreed on the Thallo side:

1. **subscriptions 2.0** (this document, this repo).
2. **`thallo-subscriptions`** — Thallo capability module for workspace SaaS
   billing against the preserved tenant facade (Extensions-page enablement,
   platform-plan admin, per-workspace status).
3. **Thallo membership/paywall integration** — two implementation slices even
   if one design doc: (a) membership catalog, checkout, account management,
   lifecycle; (b) editor gating, entitlement fields, paywall rendering,
   preview/canvas behavior. Payment correctness and content-delivery
   correctness stay separately reviewable.

Thallo remains untouched until 2.0 ships.
