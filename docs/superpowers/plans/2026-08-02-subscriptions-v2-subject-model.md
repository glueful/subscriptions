# Subscriptions 2.0 — Subject Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the 1.4.0 upgrade bridge (preparation migration + `subscriptions:prepare-v2`) and then subscriptions 2.0.0: the explicit `(tenant_uuid, subject_type, subject_uuid)` subject model with immutable `plan_uuid` references, scoped catalogs, host-provided subject validation, provider-event receipts, member entitlement resolution, and tenant-lifecycle mechanics — per `docs/superpowers/specs/2026-08-02-subscriptions-v2-subject-model-design.md`.

**Architecture:** Two release artifacts in strict order. Phase A (1.4.0, Tasks 1–3) is additive: migration `005` creates the preparation-state table and a booted-app command materializes the DB-authoritative platform catalog. Phase B (2.0.0, Tasks 4–16) lands the subject model: migration `006` refuses to run on a populated install without the preparation marker, then adds subject columns/uniques, `plan_uuid NOT NULL`, catalog scope columns, and the receipts table. Services grow a subject-aware core with the 1.x tenant API preserved as a facade.

**Tech Stack:** PHP 8.3, glueful/framework ≥ 1.57 (SchemaBuilderInterface, Connection savepoint transactions, CacheStore), phpunit 10.5 with the existing SQLite in-memory `SubscriptionsTestCase` harness, phpstan, phpcs (PSR-12, 120-col).

## Global Constraints (from the spec — every task implicitly includes these)

- Subject triple invariants (spec §1): `tenant` subjects have `subject_uuid === tenant_uuid`; `user` subjects are always scoped by both workspace and user; no sentinels in subject identity.
- `plan_key` is **immutable** on plan rows; subscriptions reference `plan_uuid`; `plan_key` on subscription rows is denormalized display/compat data refreshed on plan change (spec §3).
- Plan-key uniqueness is `(audience, owner_tenant_uuid, plan_key)`; `owner_tenant_uuid` is `NOT NULL DEFAULT ''` where `''` = platform-owned (spec §2).
- `audience='tenant'` ⇒ `owner_tenant_uuid=''`; `audience='user'` ⇒ `owner_tenant_uuid≠''` (spec §2).
- DB-authoritative catalog: config plans are seeds; no runtime overlay; no boot-time or request-time imports (spec §3).
- Default subject resolver rejects ALL user subjects; binding a host resolver enables memberships; no new config keys (spec §4, §10).
- Rate tiers are tenant-only; the member resolver strips `rate.tier.*` (spec §5).
- Lifecycle state changes and their event appends are transactional; `startFor` races are deterministic (same plan idempotent, different plan stable conflict) with savepoint isolation (spec §8).
- `subscription_events` holds only validated lifecycle transitions; provider ingress claims/audits live in `subscription_provider_event_receipts` (spec §2, §6).
- Tenant tables registered: `subscriptions`, `subscription_overrides`, `subscription_events` only; plans and receipts excluded (spec §9).
- The extension never imports Thallo or tenancy implementation classes; only `glueful/extension-contracts` interfaces and the existing soft `class_exists` probes (spec §0, §9).
- All quality gates green per task: `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs`.
- Commits: conventional style, no AI-attribution trailers.

## File Structure (final state)

```
migrations/
  005_CreateV2PreparationState.php        (new, ships in 1.4.0)
  006_SubjectModel.php                    (new, ships in 2.0.0)
src/
  Subject.php                             (new: value object)
  SubjectType.php                         (new: string constants)
  Contracts/SubjectResolverInterface.php  (new)
  Resolution/DefaultSubjectResolver.php   (new: compat default)
  Resolution/MemberEntitlementResolver.php(new)
  Http/RequireMemberEntitlement.php       (new)
  Console/PrepareV2Command.php            (new, ships in 1.4.0)
  Repositories/ProviderEventReceiptRepository.php (new)
  Lifecycle/SubscriptionSubjectDataPurger.php     (new)
  Lifecycle/TenantIntegration.php         (new: table registration + runner access)
  SubscriptionService.php                 (modified: subject core + facade)
  Catalog/PlanCatalog.php                 (modified: scoped, DB-authoritative)
  Plans/PlanManagementService.php         (modified: scope-aware)
  Plans/PlanPayloadValidator.php          (modified: audience/owner invariants)
  Projection/SubscriptionEventProjector.php (modified: receipts pipeline)
  Projection/ProviderSubscriptionEvent.php  (unchanged DTO)
  Repositories/{SubscriptionRepository, OverrideRepository,
    SubscriptionEventRepository, SubscriptionPlanRepository}.php (modified)
  Resolution/EntitlementResolver.php      (modified: subject-keyed cache)
  SubscriptionsServiceProvider.php        (modified: new bindings/aliases)
tests/… (mirrors: new Unit/Subject*, Integration/PrepareV2CommandTest,
  Integration/SubjectMigrationTest, Integration/Projection/ReceiptTest,
  Integration/Resolution/MemberEntitlementResolverTest,
  Integration/Lifecycle/*, Integration/Concurrency/PostgresSavepointTest)
```

---

# PHASE A — release 1.4.0 (upgrade bridge)

### Task 1: Preparation-state migration (`005`)

**Files:**
- Create: `migrations/005_CreateV2PreparationState.php`
- Test: `tests/Integration/MigrationsTest.php` (extend)

**Interfaces:**
- Produces: table `subscription_v2_preparation` with columns `id`, `marker_key VARCHAR(40) UNIQUE`, `catalog_signature VARCHAR(64)`, `report JSON NULL`, `prepared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`. Class name `Glueful\Extensions\Subscriptions\Database\Migrations\CreateV2PreparationState`.

- [ ] **Step 1: Write the failing test** — append to `tests/Integration/MigrationsTest.php`:

```php
public function testV2PreparationTableShape(): void
{
    $schema = $this->connection->getSchemaBuilder();
    self::assertTrue($schema->hasTable('subscription_v2_preparation'));
    foreach (['marker_key', 'catalog_signature', 'report', 'prepared_at'] as $column) {
        self::assertTrue(
            $schema->hasColumn('subscription_v2_preparation', $column),
            "missing column {$column}"
        );
    }

    // marker_key is UNIQUE: second insert with the same key must throw.
    db($this->context)->table('subscription_v2_preparation')->insert([
        'marker_key' => 'subject-model-v2', 'catalog_signature' => 'a',
    ]);
    $this->expectException(\Throwable::class);
    db($this->context)->table('subscription_v2_preparation')->insert([
        'marker_key' => 'subject-model-v2', 'catalog_signature' => 'b',
    ]);
}
```

Also add `(new CreateV2PreparationState())->up($schema);` to `tests/Support/SubscriptionsTestCase.php::setUp()` after the four existing migrations, with the matching `use Glueful\Extensions\Subscriptions\Database\Migrations\CreateV2PreparationState;` import.

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit --filter V2PreparationTableShape`
Expected: FAIL (class `CreateV2PreparationState` not found).

- [ ] **Step 3: Implement** — `migrations/005_CreateV2PreparationState.php` (mirror `004`'s file structure exactly: same namespace `Glueful\Extensions\Subscriptions\Database\Migrations`, same `MigrationInterface` shape as the sibling files — copy the class skeleton from `004_CreateSubscriptionPlansTable.php` and replace the body):

```php
public function up(SchemaBuilderInterface $schema): void
{
    if ($schema->hasTable('subscription_v2_preparation')) {
        return;
    }
    $schema->createTable('subscription_v2_preparation', function ($table): void {
        $table->bigInteger('id')->primary()->autoIncrement();
        $table->string('marker_key', 40);
        $table->string('catalog_signature', 64);
        $table->json('report')->nullable();
        $table->timestamp('prepared_at')->default('CURRENT_TIMESTAMP');
        $table->unique('marker_key');
    });
    $schema->execute();
}

public function down(SchemaBuilderInterface $schema): void
{
    $schema->dropTableIfExists('subscription_v2_preparation');
    $schema->execute();
}

public function getDescription(): string
{
    return 'Create the v2 preparation-state table (upgrade bridge, spec §3)';
}
```

- [ ] **Step 4: Run to verify pass** — `vendor/bin/phpunit --filter V2PreparationTableShape` → PASS; then full `vendor/bin/phpunit` → all green.
- [ ] **Step 5: Commit** — `git add migrations/005_CreateV2PreparationState.php tests/ && git commit -m "feat: v2 preparation-state table (005, upgrade bridge)"`

### Task 2: `subscriptions:prepare-v2` command

**Files:**
- Create: `src/Console/PrepareV2Command.php`
- Test: `tests/Integration/Console/PrepareV2CommandTest.php` (new)

**Interfaces:**
- Consumes: `PlanManagementService::importFromConfig()` (existing 1.x, verify exact name via `src/Plans/PlanManagementService.php` — it backs `subscriptions:plans:import-config`; reuse, do not duplicate import logic), `SubscriptionPlanRepository`, `SubscriptionRepository` (raw distinct plan_key query via `db($context)`), table `subscription_v2_preparation`.
- Produces: command name `subscriptions:prepare-v2` (Symfony `#[AsCommand]`, discovered by the existing `discoverCommands('...\Console', …)`); marker row protocol: delete `subject-model-v2` marker (committed), import + synthesize, verify, write single marker with `catalog_signature` = the `PlanCatalog::version()` value and JSON `report` `{imported: [...], synthesized: [...], verified_keys: N}`.

- [ ] **Step 1: Write the failing tests** — `tests/Integration/Console/PrepareV2CommandTest.php` (extend `SubscriptionsTestCase`; mirror the existing console test style in `tests/Integration/Console/` for how commands are constructed and executed with a `CommandTester`):

```php
public function testPreparesConfigPlansSynthesizesDanglingAndWritesMarker(): void
{
    // 1.x-shaped state: a config-only catalog (free/pro seeded as config in
    // SubscriptionsTestCase), one DB plan, one subscription on a config plan,
    // one subscription on a DANGLING key that exists nowhere.
    db($this->context)->table('subscription_plans')->insert([
        'uuid' => 'planaaaa0001', 'plan_key' => 'enterprise', 'display_name' => 'Enterprise',
        'entitlements' => json_encode(['x' => true]), 'status' => 'active', 'sort_order' => 0,
    ]);
    db($this->context)->table('subscriptions')->insert([
        'uuid' => 'subaaaa00001', 'tenant_uuid' => 't-1', 'plan_key' => 'pro', 'status' => 'active',
    ]);
    db($this->context)->table('subscriptions')->insert([
        'uuid' => 'subaaaa00002', 'tenant_uuid' => 't-2', 'plan_key' => 'legacy-gold', 'status' => 'active',
    ]);

    $exit = $this->runPrepare(); // helper: executes PrepareV2Command via CommandTester
    self::assertSame(0, $exit);

    // Config plans imported (create-missing): free + pro now DB rows.
    foreach (['free', 'pro'] as $key) {
        self::assertNotNull(
            (new SubscriptionPlanRepository())->findByKey($this->context, $key),
            "config plan {$key} not imported"
        );
    }
    // Dangling key synthesized as archived, empty entitlements.
    $legacy = (new SubscriptionPlanRepository())->findByKey($this->context, 'legacy-gold');
    self::assertSame('archived', $legacy['status']);
    self::assertSame([], json_decode((string) $legacy['entitlements'], true));

    // Exactly one marker with a report naming the synthesis.
    $markers = db($this->context)->table('subscription_v2_preparation')->get();
    self::assertCount(1, $markers);
    self::assertSame('subject-model-v2', $markers[0]['marker_key']);
    $report = json_decode((string) $markers[0]['report'], true);
    self::assertContains('legacy-gold', $report['synthesized']);
}

public function testRerunIsIdempotentAndReplacesTheMarker(): void
{
    $this->runPrepare();
    $first = db($this->context)->table('subscription_v2_preparation')->get()[0];
    $this->runPrepare();
    $rows = db($this->context)->table('subscription_v2_preparation')->get();
    self::assertCount(1, $rows);                       // still exactly one marker
    self::assertNotSame($first['id'], $rows[0]['id']); // replaced, not kept
    // No duplicate plans were created by the second run.
    $keys = array_column(db($this->context)->table('subscription_plans')->get(), 'plan_key');
    self::assertSame(count($keys), count(array_unique($keys)));
}

public function testFailedVerificationLeavesNoMarker(): void
{
    // Force failure: insert a subscription AFTER import would run, via a
    // repository fake is overkill — instead point the command at a validator
    // hook: simplest deterministic failure is a subscription whose plan_key is
    // empty string, which import/synthesis cannot resolve.
    db($this->context)->table('subscriptions')->insert([
        'uuid' => 'subaaaa00003', 'tenant_uuid' => 't-3', 'plan_key' => '', 'status' => 'active',
    ]);
    $exit = $this->runPrepare();
    self::assertNotSame(0, $exit);
    self::assertCount(0, db($this->context)->table('subscription_v2_preparation')->get());
}
```

- [ ] **Step 2: Run to verify failure** — class not found.
- [ ] **Step 3: Implement `PrepareV2Command`** — core flow (constructor/DI/style copied from an existing command in `src/Console/Plans/`, e.g. the import-config command, which shows how console commands obtain the `ApplicationContext` and services):

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $context = $this->context();

    // (1) Committed marker delete: an interrupted run must leave NO authority.
    db($context)->table('subscription_v2_preparation')
        ->where('marker_key', '=', 'subject-model-v2')->delete();

    // (2) Import config plans (reuse the exact import-config service path).
    $imported = $this->planManagement->importFromConfig($context); // create-missing only

    // (3) Synthesize archived empty-entitlement plans for dangling keys.
    $synthesized = [];
    $keys = array_column(
        db($context)->table('subscriptions')->select(['plan_key'])->distinct()->get(),
        'plan_key'
    );
    foreach ($keys as $key) {
        $key = (string) $key;
        if ($key === '') {
            $output->writeln('<error>subscription with empty plan_key cannot be prepared</error>');
            return self::FAILURE;
        }
        if ($this->plans->findByKey($context, $key) === null) {
            $this->plans->insert($context, [
                'uuid' => Utils::generateNanoID(12), 'plan_key' => $key,
                'display_name' => $key, 'entitlements' => [],
                'status' => 'archived', 'sort_order' => 0,
            ]);
            $synthesized[] = $key;
            $output->writeln("<comment>synthesized archived plan '{$key}' (empty entitlements)</comment>");
        }
    }

    // (4) Final verification: EVERY distinct subscription plan_key resolves.
    foreach ($keys as $key) {
        if ($this->plans->findByKey($context, (string) $key) === null) {
            $output->writeln("<error>verification failed: '{$key}' unresolved</error>");
            return self::FAILURE;
        }
    }

    // (5) Single marker + report, one transaction.
    db($context)->transaction(function () use ($context, $imported, $synthesized, $keys): void {
        db($context)->table('subscription_v2_preparation')->insert([
            'marker_key' => 'subject-model-v2',
            'catalog_signature' => PlanCatalog::fromContext($context)->version(),
            'report' => json_encode([
                'imported' => $imported, 'synthesized' => $synthesized,
                'verified_keys' => count($keys),
            ], JSON_THROW_ON_ERROR),
        ]);
    });

    $output->writeln('<info>v2 preparation complete.</info>');
    return self::SUCCESS;
}
```

If `PlanManagementService` has no reusable `importFromConfig` returning imported keys, extract one from the existing import-config command body into the service (moving logic, not duplicating), keep the command delegating, and return the imported key list.

- [ ] **Step 4: Run to verify pass** — targeted filter, then full suite + phpstan + phpcs.
- [ ] **Step 5: Commit** — `feat: subscriptions:prepare-v2 upgrade-bridge command`

### Task 3: 1.4.0 release chores

**Files:** Modify `CHANGELOG.md`, `composer.json` (`extra.glueful.version` → `1.4.0`), `README.md` (new "Upgrading to 2.0" section: maintenance window → `subscriptions:prepare-v2` → install 2.0 → `migrate:run`).

- [ ] Update the three files; CHANGELOG entry lists migration `005` + the command, states explicitly "additive only; no behavior change".
- [ ] Full suite + phpstan + phpcs green.
- [ ] Commit `chore: release 1.4.0 — v2 upgrade bridge`. Tag/release per the repo's existing release convention (see previous `Release 1.3.1` commit).

---

# PHASE B — release 2.0.0 (subject model)

### Task 4: `Subject`, `SubjectType`, resolver contract, default resolver

**Files:**
- Create: `src/SubjectType.php`, `src/Subject.php`, `src/Contracts/SubjectResolverInterface.php`, `src/Resolution/DefaultSubjectResolver.php`
- Test: `tests/Unit/SubjectTest.php`, `tests/Unit/DefaultSubjectResolverTest.php`

**Interfaces (produced — later tasks depend on these EXACT shapes):**

```php
final class SubjectType { public const TENANT = 'tenant'; public const USER = 'user'; }

final class Subject
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $type,
        public readonly string $uuid,
    ) {}
    public static function tenant(string $tenantUuid): self;          // (t, TENANT, t)
    public static function user(string $tenantUuid, string $userUuid): self;
}

interface SubjectResolverInterface
{
    public function currentTenant(ApplicationContext $context): ?string;
    public function currentUser(ApplicationContext $context): ?string;
    public function validate(ApplicationContext $context, Subject $subject): bool;
}
```

- [ ] **Step 1: Failing tests** — constructors produce the invariant triples; `DefaultSubjectResolver::validate` accepts `Subject::tenant('t-1')`, rejects `new Subject('t-1', SubjectType::TENANT, 't-2')`, rejects empty identities, rejects EVERY `Subject::user(...)`; `currentTenant` delegates to the `CurrentTenant::resolve` behavior (test via the `tenancy.tenant` request-state seam exactly as any existing CurrentTenant coverage does); `currentUser` returns null.
- [ ] **Step 2:** RED. **Step 3:** implement — `DefaultSubjectResolver::currentTenant` calls `CurrentTenant::resolve($context)` (keep `CurrentTenant` as-is; it remains the internal helper); `validate`:

```php
public function validate(ApplicationContext $context, Subject $subject): bool
{
    if ($subject->tenantUuid === '' || $subject->uuid === '') {
        return false;
    }
    return $subject->type === SubjectType::TENANT && $subject->uuid === $subject->tenantUuid;
}
```

- [ ] **Step 4:** GREEN + gates. **Step 5:** commit `feat: subject value object and resolver seam`.

### Task 5: Migration `006_SubjectModel.php` + receipts table

**Files:**
- Create: `migrations/006_SubjectModel.php`
- Test: `tests/Integration/SubjectMigrationTest.php` (new; drives `006` explicitly against a 1.x-shaped DB — do NOT add `006` to `SubscriptionsTestCase::setUp()` yet; that happens at the END of this task once shapes pass)

**Interfaces (produced):** post-006 schema exactly as spec §2, including new table `subscription_provider_event_receipts` (`uuid`, `provider_gateway`, `provider_logical_event_key NULL`, `event_type`, candidate_* ×4, resolved `tenant_uuid`/`subject_type`/`subject_uuid`/`plan_uuid` (all nullable), `outcome`, `rejection_code NULL`, `data JSON NULL`, `created_at`, `UNIQUE (provider_gateway, provider_logical_event_key)`).

- [ ] **Step 1: Failing tests** (representative set — write all):

```php
public function testRefusesPopulatedInstallWithoutPreparationMarker(): void
{
    db($this->context)->table('subscriptions')->insert([
        'uuid' => 's1', 'tenant_uuid' => 't-1', 'plan_key' => 'pro', 'status' => 'active',
    ]);
    $this->expectException(\RuntimeException::class);
    (new SubjectModel())->up($this->connection->getSchemaBuilder());
}

public function testUpgradesPreparedInstall(): void
{
    $this->preparedFixture(); // runs the REAL PrepareV2Command (Task 2) on a seeded 1.x dataset
    (new SubjectModel())->up($this->connection->getSchemaBuilder());

    $sub = db($this->context)->table('subscriptions')->where('tenant_uuid', '=', 't-1')->first();
    self::assertSame('tenant', $sub['subject_type']);
    self::assertSame('t-1', $sub['subject_uuid']);
    self::assertNotEmpty($sub['plan_uuid']); // backfilled and NOT NULL
    // old UNIQUE(tenant_uuid) gone: a user-subject row for the same tenant inserts fine
    db($this->context)->table('subscriptions')->insert([
        'uuid' => 's9', 'tenant_uuid' => 't-1', 'subject_type' => 'user', 'subject_uuid' => 'u-1',
        'plan_key' => 'pro', 'plan_uuid' => $sub['plan_uuid'], 'status' => 'active',
    ]);
    // new triple unique: duplicate subject throws
    $this->expectException(\Throwable::class);
    db($this->context)->table('subscriptions')->insert([
        'uuid' => 's10', 'tenant_uuid' => 't-1', 'subject_type' => 'user', 'subject_uuid' => 'u-1',
        'plan_key' => 'pro', 'plan_uuid' => $sub['plan_uuid'], 'status' => 'active',
    ]);
}

public function testFreshInstallSkipsMarkerRequirement(): void  // zero subscriptions → no marker needed
public function testEventsBackfillIntoAcceptedReceipts(): void  // provider events (non-null gateway+key) copied, outcome=accepted, uuid reused; manual events NOT copied
public function testPlansGainScopeColumnsAndScopedUnique(): void // audience/owner defaults; (audience,owner,plan_key) unique allows platform-pro + two workspaces' pro; drop of UNIQUE(plan_key) proven
public function testOverridesUniqueBecomesSubjectScoped(): void
```

- [ ] **Step 2:** RED (class `SubjectModel` missing).
- [ ] **Step 3: Implement `006`** — order inside `up()` (all raw data ops via `$schema->getConnection()`; portable SQL only):

```php
// (0) GUARD — spec §3: populated install requires exactly one preparation row.
$hasLegacyRows = $schema->hasTable('subscriptions') && $schema->getTableRowCount('subscriptions') > 0;
if ($hasLegacyRows) {
    $prepared = $schema->hasTable('subscription_v2_preparation')
        ? $schema->getTableRowCount('subscription_v2_preparation') : 0;
    if ($prepared !== 1) {
        throw new \RuntimeException(
            'subscriptions v2 requires the 1.4 upgrade bridge: install the final 1.x release, '
            . 'run `subscriptions:prepare-v2`, then migrate. '
            . "(preparation rows found: {$prepared}, expected exactly 1)"
        );
    }
}

// (1) Columns (alterTable): subscriptions/subscription_overrides/subscription_events each gain
//     subject_type VARCHAR(10) NOT NULL DEFAULT 'tenant', subject_uuid VARCHAR(64) NOT NULL DEFAULT ''.
//     subscriptions additionally gains plan_uuid VARCHAR(12) NULL.
//     subscription_plans gains audience VARCHAR(10) NOT NULL DEFAULT 'tenant',
//     owner_tenant_uuid VARCHAR(64) NOT NULL DEFAULT ''.
// (2) Backfill (portable UPDATEs through $schema->getConnection()):
//     UPDATE subscriptions SET subject_uuid = tenant_uuid WHERE subject_uuid = '';
//     same for overrides and events;
//     UPDATE subscriptions SET plan_uuid = (SELECT uuid FROM subscription_plans p
//        WHERE p.plan_key = subscriptions.plan_key) WHERE plan_uuid IS NULL;
// (3) Abort-if-unresolved: SELECT COUNT(*) WHERE plan_uuid IS NULL — >0 ⇒ RuntimeException
//     (names the offending keys via a second query, then throws).
// (4) Constrain: alter plan_uuid to NOT NULL; drop UNIQUE(tenant_uuid) on subscriptions;
//     add UNIQUE(tenant_uuid, subject_type, subject_uuid) as uniq_subscriptions_subject;
//     overrides: drop uniq_override_tenant_entitlement, add
//     UNIQUE(tenant_uuid, subject_type, subject_uuid, entitlement) as uniq_override_subject_entitlement;
//     plans: drop UNIQUE(plan_key), add UNIQUE(audience, owner_tenant_uuid, plan_key)
//     as uniq_plans_scope_key.
// (5) Receipts table create (full column list from Interfaces above).
// (6) Receipts backfill: INSERT INTO subscription_provider_event_receipts
//     (uuid, provider_gateway, provider_logical_event_key, event_type,
//      tenant_uuid, subject_type, subject_uuid, outcome, created_at)
//     SELECT uuid, provider_gateway, provider_logical_event_key, type,
//      tenant_uuid, subject_type, subject_uuid, 'accepted', created_at
//     FROM subscription_events
//     WHERE provider_gateway IS NOT NULL AND provider_logical_event_key IS NOT NULL;
```

Index/unique drop-and-add go through the schema builder where its alter API supports them; where it does not, use `addPendingOperation()` with per-dialect guards ONLY if unavoidable — check `Builders/` for `dropIndex`/`addIndex` alter support first and prefer it. `down()` reverses the constraint swaps, drops the receipts table and added columns (document that `plan_uuid` data loss on down is acceptable — down exists for dev only, like `001–004`).

- [ ] **Step 4:** GREEN on `SubjectMigrationTest`; then add `(new SubjectModel())->up($schema);` to `SubscriptionsTestCase::setUp()` (after `005`) and fix any fallout in existing tests (there should be none — defaults keep 1.x-shaped inserts valid; where an existing test asserts the OLD unique `tenant_uuid` behavior, update it to the triple form and note it in the commit message). Full suite + gates green.
- [ ] **Step 5:** Commit `feat!: subject-model migration 006 with receipts table and guarded upgrade`.

### Task 6: Subject-aware repositories + receipts repository

**Files:** Modify all four repositories; Create `src/Repositories/ProviderEventReceiptRepository.php`; Tests in `tests/Integration/Repositories/`.

**Interfaces (produced):**

```php
// SubscriptionRepository
public function findBySubject(ApplicationContext $c, Subject $s): ?array;   // triple match
public function updateBySubject(ApplicationContext $c, Subject $s, array $changes): void;
// findByTenant/updateByTenant REMAIN, reimplemented as: findBySubject($c, Subject::tenant($t))

// OverrideRepository
public function activeForSubject(ApplicationContext $c, Subject $s): array; // activeForTenant delegates via Subject::tenant

// SubscriptionPlanRepository — every finder gains scope:
public function findByUuid(ApplicationContext $c, string $uuid): ?array;
public function findByKeyInScope(ApplicationContext $c, string $audience, string $owner, string $key): ?array;
public function findResolvableByKeyInScope(...same signature...): ?array;
public function listInScope(ApplicationContext $c, string $audience, string $owner): array;
public function maxUpdatedAtInScope(ApplicationContext $c, string $audience, string $owner): ?string;
// 1.x names findByKey/findResolvableByKey/list/maxUpdatedAt delegate to platform scope ('tenant','')

// ProviderEventReceiptRepository
public function insertPending(ApplicationContext $c, array $row): void;     // throws on claim-unique violation
public function markAccepted(ApplicationContext $c, string $uuid, array $resolved): void;
public function markRejected(ApplicationContext $c, string $uuid, string $rejectionCode): void;
public function existsByLogicalKey(ApplicationContext $c, string $gateway, string $key): bool;
public function isUniqueViolation(\Throwable $e): bool; // extracted SHARED helper — move the existing
// SubscriptionEventRepository::isUniqueViolation body into a new
// src/Repositories/UniqueViolations.php static and delegate from both repos (spec §8's shared detector).
```

- [ ] Steps: failing tests for each new finder (triple-match semantics: `findBySubject` must NOT return a user row for `Subject::tenant`), extraction test for `UniqueViolations::isUniqueViolation` covering the sqlite + mysql + postgres message shapes already handled by the existing helper; implement; suite + gates; commit `feat: subject-aware repositories and provider-event receipt repository`.

### Task 7: Scoped, DB-authoritative `PlanCatalog`

**Files:** Modify `src/Catalog/PlanCatalog.php`; Tests `tests/Integration/Catalog/` (extend existing catalog tests).

**Interfaces (produced):**

```php
public static function fromContext(ApplicationContext $context): self;            // platform scope ('tenant','')
public static function forScope(ApplicationContext $context, string $audience, string $ownerTenantUuid): self;
public function audience(): string;
public function ownerTenantUuid(): string;
public function defaultPlan(): string;              // platform scope only; forScope(user,…) throws LogicException
public function planUuidForKey(string $planKey): ?string;                         // NEW: key→uuid in scope
public function entitlementsForUuid(string $planUuid): array;                     // NEW: uuid-first read
public function isAssignableUuid(string $planUuid): bool;                         // NEW
public function providerPriceIdForUuid(string $planUuid): ?string;                // NEW
// 1.x key-based methods (entitlementsFor/planExists/isAssignable/providerPriceId) REMAIN,
// resolving key→row WITHIN the scope, with NO config fallback (overlay removed).
public function graceDays(): int;                    // unchanged (config)
public function version(): string;                   // audience:owner:maxUpdatedAtInScope (no config hash)
```

- [ ] **Failing tests:** overlay removal (a config-only plan key resolves to nothing until imported — flip the existing overlay expectation tests to the new contract and seed DB rows via the Task 2 import instead); scope isolation (same key in platform and a workspace scope resolves independently; `forScope('user','w-1')` never sees platform rows); `defaultPlan()` throws outside the platform scope; `version()` changes when a scoped row updates and differs between scopes.
- [ ] Implement (constructor gains `audience`/`owner` with platform defaults; drop every `$this->config['plans']` read except `default_plan`/`grace_days`; delete the config-hash half of `version()`).
- [ ] Suite + gates; commit `feat!: scoped DB-authoritative plan catalog`.

### Task 8: Scope-aware plan management + immutable `plan_key`

**Files:** Modify `src/Plans/PlanManagementService.php`, `src/Plans/PlanPayloadValidator.php`, the `src/Console/Plans/*` commands, `src/Http/PlanController.php` (platform scope pinned); Tests: extend `tests/Integration/PlanManagementServiceTest.php` + console tests.

- [ ] **Failing tests:** create/update/archive accept an explicit scope `(audience, ownerTenantUuid)` and enforce spec §2's invariants (`audience='tenant'` ⇒ owner `''`; `audience='user'` ⇒ owner non-empty — violations throw the validator's existing exception type); **update rejects any `plan_key` change** (immutability — new validator rule, exact message `plan_key is immutable`); `plans:*` console commands accept `--audience=` and `--owner=` defaulting to platform scope and behave 1.x-identically without them; `PlanController` (HTTP) manages ONLY the platform scope (no new params — assert a request cannot reach a workspace scope); import-config imports into the platform scope.
- [ ] Implement; suite + gates; commit `feat!: scope-aware plan management with immutable plan keys`.

### Task 9: Subject-aware `SubscriptionService` core + tenant facade

**Files:** Modify `src/SubscriptionService.php`; Tests: extend `tests/Integration/SubscriptionServiceTest.php` + new `tests/Integration/SubscriptionServiceSubjectTest.php`.

**Interfaces (produced):** exactly spec §7's five `…For(Subject …)` methods (with `startFor(Subject $s, string $planUuid, array $opts = [])`); the five 1.x tenant methods preserved as facades (`start()` maps `planKey` → `planUuidForKey` in the platform catalog and calls `startFor`).

Key implementation requirements (each with its own failing test first):

- [ ] **Transactional lifecycle (spec §8):** every state change + its event append run inside `db($this->context)->transaction(...)` — `startFor`, `changePlanFor`, `cancelFor`, `reconcileFor` (the reconcile drift-write + event). Event rows now include the subject columns (`tenant_uuid` = subject tenantUuid, `subject_type`, `subject_uuid`).
- [ ] **Deterministic race (spec §8):** `startFor` inserts inside a transaction with the insert wrapped in a NESTED `transaction(...)` call (the framework promotes nesting to savepoints — this is the PG isolation). On `UniqueViolations::isUniqueViolation`: re-read via `findBySubject`; same `plan_uuid` → return winner row (idempotent); different `plan_uuid` → throw new `SubscriptionConflictException` (create `src/SubscriptionConflictException.php`, extends `\RuntimeException`) and change nothing. Test both outcomes by pre-inserting the winner row before calling `startFor`.
- [ ] **Subject validation (spec §4):** constructor gains `SubjectResolverInterface $subjects`; every `…For` method first requires `$this->subjects->validate($this->context, $s)` (throws `\InvalidArgumentException('invalid subject')` otherwise) and audience-matching: the plan row's `(audience, owner_tenant_uuid)` must be `('tenant','')` for tenant subjects and `('user', $s->tenantUuid)` for user subjects (test: a bound permissive fake resolver + wrong-scope plan → rejected).
- [ ] **Plan reference:** rows are written with BOTH `plan_uuid` (authoritative) and denormalized `plan_key` (read from the plan row); `changePlanFor` refreshes both + `provider_price_id` from the target plan row.
- [ ] Facade-equivalence test file: copy the strongest existing 1.x lifecycle test METHODS verbatim into `SubscriptionServiceFacadeTest.php` (per spec §11.2 — they must pass unmodified apart from the test-case import).
- [ ] Suite + gates; commit `feat!: subject-aware subscription lifecycle with preserved tenant facade`.

### Task 10: Receipts-first projector

**Files:** Modify `src/Projection/SubscriptionEventProjector.php`; Tests: extend `tests/Integration/Projection/` (+ new `ReceiptProjectionTest.php`).

Behavior (each bullet test-first; keep the existing state-machine mapping `computeChanges()` byte-identical):

- [ ] **Claim-first receipts:** `project()` opens ONE transaction; inserts a `pending` receipt (candidate identity from normalized metadata: `candidate_tenant_uuid`, `candidate_subject_type`, `candidate_subject_uuid`, `candidate_plan_uuid`; sanitized `data`) — the receipt's `(gateway, logical_key)` unique is the FIRST claim; a duplicate loses here (existing cheap read-side early-out now checks the receipts table).
- [ ] **`subscription.created` requires the complete triple** in metadata AND `SubjectResolverInterface::validate` passing AND plan-audience coherence → otherwise `markRejected` with an allowlisted code (`missing_subject`, `invalid_subject`, `plan_scope_mismatch`, `unmapped_subscription`) and COMMIT the rejected receipt; NO `subscription_events` row, no state change. The 1.x tenant-metadata relink recovery path survives but only for validated tenant subjects (the relink block in `mapToSubscription` keeps its no-move rule and logging).
- [ ] **Later event types:** row located by `(gateway, provider_subscription_id)` as today; any subject metadata present is cross-checked against the stored triple → mismatch = `markRejected('subject_mismatch')`, no state/event write.
- [ ] **Accepted path:** append the validated `subscription_events` row (with subject columns, backstop unique intact), apply changes via `updateBySubject`, `markAccepted` with the resolved identity — all in the one transaction.
- [ ] **Transient failure:** any non-unique-violation exception rolls the whole transaction back (pending receipt vanishes → provider retry works). Test by making the subscriptions update throw once via a failing repository double.
- [ ] Suite + gates; commit `feat!: receipts-first provider projection with subject revalidation`.

### Task 11: Member entitlement resolution + middleware

**Files:** Create `src/Resolution/MemberEntitlementResolver.php`, `src/Http/RequireMemberEntitlement.php`; Modify `src/Resolution/EntitlementResolver.php` (cache key: replace the `'subscriptions.ent'` prefix tuple with one embedding the full subject triple; tenant path uses `Subject::tenant`), `src/Resolution/EffectivePlanResolver.php` untouched. Tests: `tests/Integration/Resolution/MemberEntitlementResolverTest.php`, `tests/Integration/Http/RequireMemberEntitlementTest.php` (mirror the existing `RequireEntitlement` test's request/middleware harness).

**Interfaces (produced):**

```php
final class MemberEntitlementResolver
{
    // same ctor deps as EntitlementResolver plus SubjectResolverInterface is NOT needed here
    public function resolveMap(ApplicationContext $context, string $tenantUuid, string $userUuid): array;
}
```

- [ ] **Failing tests:** no row + no override → `[]`; no row + active override `['content.premium' => true]` → exactly that map; expired override → `[]` again; a membership row on a workspace plan resolves that plan's entitlements via `EffectivePlanResolver` with **empty-string default plan** (no implicit default — pass `''` and treat it as "no plan → `[]` base map"); `rate.tier.pro => true` in a member plan is ABSENT from the resolved map (strip + one log line via the projector's defensive logger pattern); tenant resolver output is unchanged by member fixtures (isolation, spec §11.6).
- [ ] `RequireMemberEntitlement::handle`: resolves `currentTenant()` AND `currentUser()` via `SubjectResolverInterface`; both present → allow iff the member map grants the param entitlement (truthy boolean, mirroring `DefaultEntitlementChecker::allows` semantics — reuse its value-interpretation logic by extracting a small shared `EntitlementValue::allows(mixed $v): bool` helper used by both); either missing → 403 unless `permissive_middleware`.
- [ ] Suite + gates; commit `feat: member entitlement resolver and require_member_entitlement middleware`.

### Task 12: Tenant lifecycle — table registration, context runner, purger

**Files:** Create `src/Lifecycle/TenantIntegration.php`, `src/Lifecycle/SubscriptionSubjectDataPurger.php`; Modify `src/SubscriptionsServiceProvider.php` (boot registration); Tests: `tests/Integration/Lifecycle/TenantIntegrationTest.php`, `PurgerTest.php` with a recording fake `TenantContextRunner`/`TenantTableRegistry` (define the fakes in `tests/Support/`; the real interfaces come from `glueful/extension-contracts`, add it to `require-dev`).

- [ ] **Registration:** when `interface_exists(TenantTableRegistry::class)` and the container binds it, register exactly `subscriptions`, `subscription_overrides`, `subscription_events` (spec §9) outside any gate; plans + receipts never. Test asserts the exact three names.
- [ ] **Runner usage:** `TenantIntegration::runAsTenantOr(ApplicationContext $c, string $tenantUuid, callable $fn)` and `runAsSystemOr(...)` — use the bound `TenantContextRunner` when present, else call `$fn` directly. `SubscriptionService::…For` wraps repository work in `runAsTenantOr($s->tenantUuid, …)`; projector `project()` and the purger wrap in `runAsSystemOr` (they must find rows before tenant context exists). Recording-runner test proves the mode per path (spec §11.9).
- [ ] **Purger** (spec §9): `purgeSubject(Subject $subject)` — user form deletes that subject's subscription + overrides + events + receipts (resolved OR candidate triple exact match), never plans; tenant form deletes all rows whose resolved-or-candidate tenant matches, then `audience='user' AND owner_tenant_uuid=<t>` plans only. Both idempotent (second run deletes zero), transactional, and tested against fixtures containing sibling users, foreign workspaces, and platform plans that must survive.
- [ ] Suite + gates; commit `feat: tenant-table registration, context-runner discipline, and subject purger`.

### Task 13: Console subject options

**Files:** Modify `src/Console/{ShowCommand,SetPlanCommand,ReconcileCommand}.php` (actual file names per `src/Console/` — keep 1.x signatures/behavior verbatim without the new options); Tests: extend `tests/Integration/Console/`.

- [ ] `--subject-type=` (`tenant`|`user`, default `tenant`) and `--subject-uuid=` (default: the tenant argument) on all three; `subscriptions:set-plan` resolves the plan within the audience-matching scope (user subjects → the workspace's member catalog). Invalid combos (user type without uuid) exit non-zero with a one-line error. Tests for default-behavior preservation + one user-subject flow each.
- [ ] Suite + gates; commit `feat: subject options for subscription console commands`.

### Task 14: Provider wiring, config, docs

**Files:** Modify `src/SubscriptionsServiceProvider.php`, `config/subscriptions.php` (comment updates only — no key changes, spec §10), `README.md`, `docs/BRING_YOUR_OWN_PROVIDER.md`, `CHANGELOG.md`.

- [ ] `services()`: bind `SubjectResolverInterface => DefaultSubjectResolver` (shared; hosts override the binding); register `MemberEntitlementResolver`, `ProviderEventReceiptRepository`, `SubscriptionSubjectDataPurger`, `RequireMemberEntitlement`; middleware alias `require_member_entitlement`; keep every existing binding. `boot()` adds the Task-12 tenant-table registration call. Extend `ServiceProviderWiringTest` for each new id + alias.
- [ ] Docs: README gains the two-layer model table (spec §0), the metadata contract (spec §6), the upgrade path (1.4.0 → prepare → 2.0), and "binding the resolver enables memberships"; BYOP doc gains the receipt/rejection semantics.
- [ ] Suite + gates; commit `feat: 2.0 wiring, resolver binding seam, and documentation`.

### Task 15: PostgreSQL savepoint proof (env-gated)

**Files:** Create `tests/Integration/Concurrency/PostgresSavepointTest.php`.

- [ ] Test skips (`markTestSkipped`) unless `SUBSCRIPTIONS_TEST_PG_DSN` (+`_USER`/`_PASS`) env vars are set; when set, builds a `Connection` against PG, runs migrations 001–006, then inside one transaction: pre-insert a subject row, call `startFor` for the same subject (unique violation inside the savepoint), assert the OUTER transaction still commits a subsequent write (the poisoned-transaction failure mode this exists to prevent), and assert same-plan idempotency + different-plan `SubscriptionConflictException` behave identically to SQLite. Document the DSN vars in README's testing section; add a CI job provisioning postgres (mirror the repo's existing CI workflow file structure).
- [ ] Local run: skip path green; if a local PG is available, full path green. Commit `test: env-gated PostgreSQL savepoint isolation proof`.

### Task 16: Release 2.0.0

- [ ] `composer.json`: `extra.glueful.version` → `2.0.0`; require-dev additions from Tasks 12/15 retained.
- [ ] `CHANGELOG.md`: breaking-changes section (schema, catalog authority, overlay removal, projector rejection semantics), upgrade instructions referencing the 1.4.0 bridge, new-features section.
- [ ] Full gates: `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs` — all green.
- [ ] Final whole-diff review pass against the spec's §-by-§ checklist; then commit `chore: release 2.0.0 — subject model` and tag per repo convention.

---

## Self-review (performed at write time)

- **Spec coverage:** §1→Task 4; §2→Tasks 5–6; §3→Tasks 1–2, 7–8; §4→Tasks 4, 9, 10; §5→Task 11; §6→Task 10 (+14 docs); §7→Task 9; §8→Tasks 9, 15; §9→Task 12; §10→Task 14; §11.1→Tasks 2, 5; §11.2→Task 9; §11.3→Tasks 7–8; §11.4→Tasks 4, 9, 10; §11.5→Task 10; §11.6→Task 11; §11.7→Tasks 9, 15; §11.8→Task 11; §11.9→Task 12. No uncovered spec section.
- **Known intentional deferrals:** none within scope; out-of-scope list (spec §12) untouched.
- **Type consistency:** `Subject`/`SubjectType`/`SubjectResolverInterface` (Task 4) are the shapes consumed by Tasks 6, 9, 10, 11, 12, 13; `UniqueViolations` (Task 6) consumed by Tasks 9–10; `PlanCatalog::forScope`/`planUuidForKey` (Task 7) consumed by Tasks 8–9, 13; `SubscriptionConflictException` defined in Task 9, referenced in Task 15.
- **Placeholder scan:** the two "per `src/Console/…` actual file names" notes instruct the engineer to read real file names rather than inventing them — acceptable reads, not placeholders; no TBD/TODO items remain.
