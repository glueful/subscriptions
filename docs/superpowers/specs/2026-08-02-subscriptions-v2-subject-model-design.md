# Subscriptions 2.0 — Subject Model Design

**Status:** reviewed design, ready for implementation planning.
**Target release:** 2.0.0 (semver major; breaking schema + catalog contract changes with a preserved tenant facade).
**Source baseline:** 1.3.1 (`glueful/subscriptions`, framework `>= 1.57.0`).
**Upgrade-bridge target:** 1.4.0 (additive preparation migration + command,
released before 2.0.0).
**Upgrade bridge:** existing 1.x installations MUST first install the final 1.x
release and run `subscriptions:prepare-v2` (§3). A direct 1.3.1 → 2.0 data
migration is unsupported because framework migrations receive a schema builder,
not the application context required to read the host-effective plan config.

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
  (§4). The host resolver rejects host-defined sentinels such as `default` and
  proves that the workspace exists; the compatibility default can reject only
  empty or incoherent identities (§4). This guarantees enabling tenancy later
  moves **zero rows** in a correctly bound host.

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

## 2. Schema changes (migration `006_SubjectModel.php`)

All changes are additive-then-constrain, with in-migration backfill so every 1.x
row survives untouched in meaning.

### `subscriptions`
- **Add** `subject_type VARCHAR(10) NOT NULL DEFAULT 'tenant'`.
- **Add** `subject_uuid VARCHAR(64) NOT NULL DEFAULT ''`; backfill
  `subject_uuid = tenant_uuid` for all existing rows, then treat `''` as
  invalid at the application layer (no DB-level check for portability).
- **Add** `plan_uuid VARCHAR(12) NULL` (FK-by-convention to
  `subscription_plans.uuid`); backfill it in §3, then alter it to **NOT NULL in
  the same migration**. Failure to resolve any row aborts the migration instead
  of admitting a partially upgraded catalog. `plan_key` remains as a
  denormalized display/compat column.
- **Drop** `UNIQUE (tenant_uuid)`.
- **Add** `UNIQUE (tenant_uuid, subject_type, subject_uuid)` —
  one subscription row per subject (§8). Existing rows satisfy this trivially.
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
  retained as a defense-in-depth backstop for accepted provider events. Every
  row in this table is a validated domain event and therefore always carries a
  valid subject triple.

### `subscription_provider_event_receipts`

New provider-ingress claim/audit table, deliberately separate from validated
subscription lifecycle events:

- `uuid VARCHAR(12)`, `provider_gateway VARCHAR(50)`,
  `provider_logical_event_key VARCHAR(191) NULL`, `event_type VARCHAR(40)`.
- Nullable candidate identity columns (`candidate_tenant_uuid`,
  `candidate_subject_type`, `candidate_subject_uuid`, `candidate_plan_uuid`)
  record what the provider supplied. Separate nullable resolved identity columns
  (`tenant_uuid`, `subject_type`, `subject_uuid`, `plan_uuid`) record the local
  authoritative subject when projection reached one. Either set may be
  incomplete because malformed and mismatched events are the reason this table
  exists.
- `outcome VARCHAR(20)` (`pending` | `accepted` | `rejected`), nullable allowlisted
  `rejection_code`, sanitized normalized `data`, and `created_at`.
- `UNIQUE (provider_gateway, provider_logical_event_key)` remains the
  claim-first dedupe authority whenever a logical key is present. The existing
  event-table unique remains a second backstop, not the first claim.

The projector inserts a pending receipt inside its projection transaction. A
deterministic validation rejection updates it to `rejected` and commits without
creating a `subscription_events` row. A valid projection appends the domain
event, updates the receipt to `accepted`, and commits both with the state change.
Unexpected/transient failures roll back the receipt so the provider can retry.
During `006`, every existing provider lifecycle event with a non-null gateway
and logical key is copied into an `accepted` receipt after event subject
backfill, reusing the event UUID in the separate table. A post-upgrade replay of
a pre-2.0 event therefore loses at the new claim authority instead of repeatedly
colliding only at the legacy event-table backstop.

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
  subscriptions point at `plan_uuid`, never at the scope-relative
  `plan_key` (§3).

## 3. Plan identity and catalog ownership

### DB-authoritative catalog
1.x overlays DB plans on top of `config('subscriptions.plans')` at resolve time.
2.0 makes the **database the single authority**:

- `config('subscriptions.plans')` becomes **seed data only**, imported through
  booted application commands and never by a migration or at boot (no
  per-request DB write-checks): the final-1.x `subscriptions:prepare-v2`
  command materializes the upgrade catalog, and the 2.0
  `subscriptions:plans:import-config` command handles fresh installs and later
  create-missing imports. Adding a new config plan after 2.0 therefore requires
  running the import command — config never shadows or overrides a DB row again.
- `PlanCatalog` reads one scope at a time:
  `PlanCatalog::for(audience, ownerTenantUuid)` with the platform catalog as
  `for('tenant', '')`. `entitlementsFor()`, `isAssignable()`,
  `providerPriceId()`, `version()` all operate within the selected scope.
  `version()` (the cache signature) incorporates the scope.
- `default_plan` remains a config key **for the platform catalog only** and is
  resolved key→uuid at runtime; memberships have no implicit default plan (no
  membership row ⇒ no plan entitlements, while explicit subject overrides may
  still grant complimentary access as pinned in §5).

### Immutable references
- Subscriptions store `plan_uuid`. `plan_key` on the subscription row is
  denormalized for display and for the 1.x facade, refreshed on plan change.
- `plan_key` remains **immutable**, preserving the 1.x API contract and keeping
  the denormalized compatibility column truthful. `display_name` may change
  without touching subscription rows. Archiving a plan keeps it resolvable for
  existing subscribers (unchanged 1.x behavior, now via uuid). Any future key
  rename is a separately designed operation; an ordinary patch cannot do it.

### Executable upgrade choreography

Framework migrations are constructed without dependency injection and receive
only `SchemaBuilderInterface`; the host-effective `subscriptions.plans` config,
repositories, and logger are therefore unavailable inside `006`. Catalog
materialization is split deliberately:

1. Version 1.4.0 adds migration `005_CreateV2PreparationState.php` and
   idempotent `subscriptions:prepare-v2`. Running the command through the normal
   booted application imports every effective config plan into
   `subscription_plans`, synthesizes an archived empty-entitlement plan for
   every distinct subscription `plan_key` that is still unresolved, and writes
   one preparation-state row only after a successful final verification. Its
   deterministic report and each synthesis are logged. Re-running it replaces
   the marker after re-verification and otherwise changes nothing.
2. Operators put subscription writes into maintenance/read-only mode, run the
   command as the final 1.x action, then install 2.0. Before its first schema
   change, `006` checks with the existing schema-builder inspection API: a
   populated `subscriptions` table requires exactly one preparation-state row.
   Missing/duplicate state throws before DDL. It then adds the scope/subject
   columns, backfills `plan_uuid` with a set-based join against the prepared
   platform catalog, and makes `plan_uuid` NOT NULL. The maintenance window
   prevents a config-overlay subscription from appearing after preparation;
   the NOT NULL conversion remains the final race/corruption backstop.
3. A fresh 2.0 install has zero legacy subscriptions, so `006` does not require
   a preparation marker. Its install sequence is
   migrations followed by `subscriptions:plans:import-config`; diagnostics fail
   loudly while `default_plan` has no platform row. There is no config-overlay
   fallback, boot-time write, or request-time auto-import.

The migration test harness runs the real 1.x preparation command against the
1.x-shaped fixture before applying `006`; the bridge and subject migration are
two separate release artifacts and two separately tested failure boundaries.

The preparation table is deliberately small and non-tenant-owned:
`marker_key VARCHAR(40) UNIQUE`, `catalog_signature VARCHAR(64)`, JSON
`report`, and `prepared_at`. The command first deletes the
`subject-model-v2` marker in a committed operation, then imports/synthesizes,
verifies every subscription key resolves, and writes the sole marker in one
transaction. A failure or interrupted rerun therefore leaves no authoritative
marker. `006` retains the row as upgrade provenance after success.

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
  returns null. For backward compatibility, `validate()` accepts
  `subject_type='tenant'` iff `subject_uuid === tenant_uuid` and both are
  non-empty; without a host authority it cannot prove that an arbitrary opaque
  tenant id exists or distinguish a host-defined sentinel such as `default`.
  Strict existence and sentinel rejection are obligations of the host binding.
  The default **rejects every `subject_type='user'` subject** — user
  subscriptions are inert until the host binds a resolver that can vouch for
  users. This is the capability switch: *memberships are enabled only when
  the host resolver positively resolves AND validates user subjects; a
  tenant-only host resolver binds without enabling them*.
- **Write-path enforcement**: `SubscriptionService` and
  `PlanManagementService` validate the subject (and plan-audience match:
  a `user` subject may only hold an `audience='user'` plan whose
  `owner_tenant_uuid` equals the subject's `tenant_uuid`; a `tenant` subject
  only an `audience='tenant'` platform plan) before any insert/update.
- **Webhook revalidation** (§6): projection re-validates the complete identity
  from provider metadata through the same contract before mutating state. A
  webhook naming a nonexistent user, a foreign workspace, or a mismatched
  triple is rejected and logged; the provider-event receipt records the attempt
  and allowlisted rejection code, while no validated lifecycle event is created.

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
- Memberships have no implicit plan: when no subscription row exists, member
  resolution starts from an empty entitlement map. Active user-subject
  overrides are then applied normally. This deliberately permits explicit
  complimentary grants without manufacturing a subscription row; absent both a
  row and an override, the result is empty.
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

**Delivery and retry contract (payvia ≥2.4):**

The payvia provider integration (2.4.0+) ships a `StrictPayviaSubscriptionEventBridge`
that honors the strict payment event lane design. This bridge implements the
ownership-aware supports() gate (closed six-type set + non-empty
`gateway_subscription_id` + local-mapping-or-glueful_consumer-marker proof for
every type EXCEPT `subscription.created`, which is routed on shape alone — see
the spec-owner ruling documented on `StrictPayviaSubscriptionEventBridge`) and
registers a strict-mode lane whose delivery is **at-least-once, with a mandatory
idempotency obligation** on the listener (discharged here by the projector's
claim-first receipt gate). Payvia ≥2.4 requires subscriptions 2.0+; payvia ≤2.3
degrades to fault-isolated bus delivery (default, non-strict lane) on version mismatch,
logged at boot. See `docs/superpowers/specs/2026-08-02-strict-payment-event-lane-design.md`
in the payvia repository for the full lane design and integration details.

**Skew-guard and container tag degradation (strict mode):**

When running in strict mode with a missing compiled-container tag, the DI container
logs a CRITICAL diagnostic and degrades to bus delivery. Cache invalidation is
available via `di:container:compile --force`. This is an operational safeguard,
and it is LOSSY: under bus/fallback delivery the retryable-unmapped signal is
swallowed by fault-isolated dispatch, so unmapped events are PERMANENTLY LOST
rather than retried later. Treat a stale container tag in strict mode as a
deploy/caching defect to fix immediately, not a tolerable steady state.

- **Metadata contract:** every provider subscription created by a host MUST
  carry `tenant_uuid`, `subject_type`, `subject_uuid`, and `plan_uuid` in its
  metadata. The payvia bridge normalizes these into
  `ProviderSubscriptionEvent::$normalized`; the projector requires the
  complete triple for `subscription.created` and validates it (§4) — events
  without a resolvable, valid subject are rejected into a provider-event receipt
  (§2), not silently mapped to a tenant as 1.x could and not inserted as a
  validated lifecycle event.
- For post-creation events (`updated`, `past_due`, `canceled`,
  `payment.succeeded`, `invoice.paid`) the projector resolves the local row by
  `(provider_gateway, provider_subscription_id)` first (unchanged), then
  cross-checks any subject metadata present against the stored triple —
  mismatch ⇒ rejected receipt + log, with no state or lifecycle-event write.
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
  transaction, with the insert isolated by a savepoint so a PostgreSQL unique
  violation never poisons the surrounding transaction. A shared
  unique-violation detector is extracted from the existing plan/event helpers;
  1.x `SubscriptionService::start()` itself has no race handling and is not
  claimed as precedent. After a lost race the caller re-reads the winner:
  same `plan_uuid` returns that row idempotently; a different `plan_uuid`
  raises a stable domain conflict and changes nothing. `startFor()` never
  silently becomes `changePlanFor()`.
- The subscription insert and its `created` lifecycle event append commit in
  the same transaction. The same state-plus-event atomicity applies to plan
  changes, cancellation, and reconciliation introduced or touched by the 2.0
  subject refactor.
- One **active membership** per (workspace, user) in v1 of the model:
  concurrent memberships / add-on stacks are **explicitly deferred** — the
  single-row-per-subject constraint is the enforcement, and any future
  stacking design supersedes this spec deliberately, not accidentally.
- Projection dedupe by `(gateway, logical_event_key)` is unchanged and remains
  insert-first, but the first claim now belongs to
  `subscription_provider_event_receipts`; `subscription_events` records only
  accepted, validated lifecycle transitions (§2).

## 9. Tenant and subject lifecycle

The extension owns the mechanics for its data; hosts own the decision to invoke
them from their tenancy and privacy workflows.

- When the optional contracts package and a `TenantTableRegistry` binding are
  present, the provider registers `subscriptions`, `subscription_overrides`,
  and `subscription_events` outside any feature gate. Each has a conventional
  required `tenant_uuid` and is safe for tenant query enforcement.
- `subscription_plans` is deliberately **not** registered as an ordinary tenant
  table: it mixes platform rows (`owner_tenant_uuid=''`) with workspace-owned
  rows and uses a differently named ownership column. Provider-event receipts
  are also not registered because rejected candidates may have no valid tenant.
- When `TenantContextRunner` is available, subject-scoped service work runs
  through `runAsTenant($subject->tenantUuid, ...)`; trusted provider projection,
  preparation, and purge operations that must discover or remove rows before a
  tenant context is known run through `runAsSystem(...)`. The direct path is
  used only when no tenancy contracts are installed. Registering the tables must
  therefore never break provider lookup or background reconciliation under
  tenancy enforcement.
- A host-neutral `SubscriptionSubjectDataPurger` provides
  `purgeSubject(Subject $subject)`. For a user subject it deletes that user's
  subscription, overrides, validated lifecycle events, and receipts whose
  resolved **or** candidate triple exactly matches. It leaves the workspace's
  plans intact. For a tenant subject it deletes every subscription, override,
  event, and receipt whose resolved or candidate tenant matches that workspace,
  then deletes
  only `audience='user' AND owner_tenant_uuid=<workspace>` plans; platform plans
  are never touched. Operations are idempotent and transactionally ordered.
- No automatic purge runs merely because validation starts failing. Deleted-user
  billing data is retained by default until the host's explicit privacy workflow
  invokes `purgeSubject(Subject::user(...))`. Hard workspace purge integrations
  MUST invoke the tenant form. Thallo Phase 2 supplies the adapters to its purge
  registry; the extension never imports Thallo classes.
- No adoption/rekey operation is needed for the single-site → tenancy transition:
  §1 requires the same real workspace UUID before and after enablement. A host
  that changes workspace identity is outside this contract and must perform its
  own explicit migration.

## 10. Configuration changes

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

## 11. Testing strategy

Extends the existing phpunit suite (all new behavior unit-tested against the
repository fakes / in-memory DB the suite already uses). The one exception is
the PostgreSQL savepoint proof in item 7: it is an env-gated integration test
(skipped, not failed, when no PostgreSQL DSN is provided; CI provisions one):

1. **Preparation + migration:** the real final-1.x `prepare-v2` command runs
   against a seeded 1.x dataset (config-only plans, DB plans, subscriptions,
   overrides, events), imports config plans, synthesizes/logs dangling keys, and
   is idempotent. A populated database without exactly one preparation marker
   is rejected before `006` makes its first schema change. `006` then produces
   `plan_uuid NOT NULL`, subject columns
   backfilled as `('tenant', tenant_uuid)`, new uniques in place, and old
   `UNIQUE(tenant_uuid)` gone. A fresh-install fixture proves the zero-row marker
   exemption, explicit post-migration import, and missing-default diagnostic.
   Existing provider events are backfilled into accepted receipts, and replaying
   one after upgrade is a no-op at the receipt claim.
2. **Facade equivalence:** every 1.x tenant call produces byte-identical rows
   to the subject-aware equivalents; a 1.x-shaped test copied from the current
   suite must pass unmodified against 2.0.
3. **Catalog scoping:** key collisions across scopes coexist (`platform pro`
   vs two different workspaces' `pro`); cross-scope resolution is impossible
   (a user subject cannot start a platform plan, a tenant subject cannot start
   a member plan, wrong-owner member plans rejected).
4. **Validator seams:** default resolver rejects all user subjects and accepts
   only non-empty coherent tenant self-subjects; a strict bound fake rejects its
   host sentinel/nonexistent identities and enables valid users; write-path and
   projection-path both enforce.
5. **Webhook revalidation:** created-event without complete triple rejected;
   mismatched subject metadata on later events rejected; both leave rejected
   provider-event receipts and no lifecycle rows; valid flows commit an accepted
   receipt, lifecycle event, and state mutation atomically. Transient failure
   rolls the receipt back so retry succeeds.
6. **Resolver separation:** membership entitlements never appear in
   `EntitlementResolver`/`DefaultEntitlementChecker` output; `rate.tier.*`
   stripped from member resolution; tier resolver output unchanged by any
   membership fixture.
7. **Concurrency:** duplicate `startFor` races exercise the unique-violation
   path for both subject types; same-plan losers return the winner, different-plan
   losers receive the stable conflict with state unchanged; PostgreSQL proves
   the savepoint leaves the outer transaction usable.
8. **Membership override base:** no row/no override resolves empty; no row plus
   an active grant resolves that grant; expiry/removal returns to empty; tenant
   default-plan behavior remains unchanged.
9. **Lifecycle:** table registration contains exactly the three conventional
   tenant tables; user purge cannot touch sibling users or plans; tenant purge
   removes all workspace subjects and member plans while preserving platform
   plans and foreign workspaces; both purge forms are idempotent. A recording
   `TenantContextRunner` proves tenant-scoped calls and system-only projector /
   purge paths use the correct mode; the contracts-absent path remains usable.

## 12. Out of scope (deliberately)

- Per-workspace **platform** catalogs (platform plans stay global).
- Multiple concurrent memberships / add-on stacking (§8).
- Proration, invoicing, metering, dunning UI.
- Any checkout/storefront HTTP surface (host-owned; Thallo Phase 3).
- Workspace-staff plan-management HTTP routes (host-owned; Thallo Phase 3).
- Host UI of any kind.

## 13. Program context (for cross-repo readers)

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
