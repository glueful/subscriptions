# Subscriptions 2.0 — Subject Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the 1.4.0 upgrade bridge (preparation migration + `subscriptions:prepare-v2`) and then subscriptions 2.0.0: the explicit `(tenant_uuid, subject_type, subject_uuid)` subject model with immutable `plan_uuid` references, scoped catalogs, host-provided subject validation, provider-event receipts, member entitlement resolution, and tenant-lifecycle mechanics — per `docs/superpowers/specs/2026-08-02-subscriptions-v2-subject-model-design.md`.

**Architecture:** Two release artifacts in strict order. Phase A (1.4.0, Tasks 1–3) is additive: migration `005` creates the preparation-state table and a booted-app command materializes the DB-authoritative platform catalog. Phase B (2.0.0, Tasks 4–16) lands the subject model. Tasks 5–8 build and test the v2 schema and APIs behind an isolated v2 harness while the shared 1.x harness and facades remain operational. Task 9 is the single coordinated activation boundary: it applies migration `006` to the shared harness, seeds DB plans, updates every legacy fixture/write path, removes transitional catalog behavior, and switches the preserved tenant API onto the subject-aware core. No intermediate commit may put the common suite on a schema its production services cannot write.

**Tech Stack:** PHP 8.3, glueful/framework ≥ 1.57 (SchemaBuilderInterface, Connection savepoint transactions, CacheStore), phpunit 10.5 with the existing SQLite in-memory `SubscriptionsTestCase` harness, phpstan, phpcs (PSR-12, 120-col).

## Global Constraints (from the spec — every task implicitly includes these)

- Subject triple invariants (spec §1): `tenant` subjects have `subject_uuid === tenant_uuid`; `user` subjects are always scoped by both workspace and user. The default compatibility resolver rejects empty or incoherent identities and all user subjects; a host-bound production resolver additionally rejects that host's sentinel/default identities.
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
  Repositories/UniqueViolations.php       (new: shared dialect detector)
  Projection/ProviderReceiptData.php      (new: closed receipt projection)
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
  Support/V2SubscriptionsTestCase.php    (isolated post-006 harness until Task 9)
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
- Modify: `src/Plans/PlanManagementService.php` (defer audits with `Connection::afterCommit()`)
- Test: `tests/Integration/Console/PrepareV2CommandTest.php` (new)

**Interfaces:**
- Consumes the existing exact API `PlanManagementService::importConfig(bool $force = false, string $status = 'active'): array`; the command calls `importConfig(false, 'active')` and derives imported keys from the returned decoded plan rows. It also consumes `SubscriptionPlanRepository`, a raw distinct-plan-key read via `db($context)`, and `subscription_v2_preparation`.
- Produces command `subscriptions:prepare-v2`, discovered by the existing provider. Protocol: delete the old `subject-model-v2` marker in its own committed statement; then one outer transaction performs config import, dangling-plan synthesis, final verification, and insertion of exactly one replacement marker. Any failure rolls back every imported/synthesized plan and leaves no marker. Success messages and plan audit records are emitted only after the outer transaction commits.

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
    self::assertSame([], $legacy['entitlements']); // repository rows are already decoded

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
    self::assertNull((new SubscriptionPlanRepository())->findByKey($this->context, 'free'));
    self::assertNull((new SubscriptionPlanRepository())->findByKey($this->context, 'pro'));
    self::assertSame([], $this->recordingAudit->records());
}
```

- [ ] **Step 2: Run to verify failure** — class not found.
- [ ] **Step 3: Implement `PrepareV2Command`** — core flow (constructor/DI/style copied from an existing command in `src/Console/Plans/`, e.g. the import-config command, which shows how console commands obtain the `ApplicationContext` and services):

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $context = $this->context();

    // (1) Committed marker delete: an interrupted or failed run leaves NO authority.
    db($context)->table('subscription_v2_preparation')
        ->where('marker_key', '=', 'subject-model-v2')->delete();

    try {
        $result = db($context)->transaction(function () use ($context): array {
            // (2) Nested service transactions become savepoints under this authority.
            $importedRows = $this->planManagement->importConfig(false, 'active');
            $imported = array_values(array_map(
                static fn (array $row): string => (string) $row['plan_key'],
                $importedRows
            ));

            // (3) Synthesize archived empty-entitlement plans for dangling keys.
            $synthesized = [];
            $keys = array_column(
                db($context)->table('subscriptions')->select(['plan_key'])->distinct()->get(),
                'plan_key'
            );
            foreach ($keys as $key) {
                $key = (string) $key;
                if ($key === '') {
                    throw new \RuntimeException('subscription with empty plan_key cannot be prepared');
                }
                if ($this->plans->findByKey($context, $key) === null) {
                    $this->plans->insert($context, [
                        'uuid' => Utils::generateNanoID(12), 'plan_key' => $key,
                        'display_name' => $key, 'entitlements' => [],
                        'status' => 'archived', 'sort_order' => 0,
                    ]);
                    $synthesized[] = $key;
                }
            }

            // (4) Final verification under the same transaction.
            foreach ($keys as $key) {
                if ($this->plans->findByKey($context, (string) $key) === null) {
                    throw new \RuntimeException("verification failed: '{$key}' unresolved");
                }
            }

            // (5) The marker is the final write in the same transaction.
            db($context)->table('subscription_v2_preparation')->insert([
                'marker_key' => 'subject-model-v2',
                'catalog_signature' => PlanCatalog::fromContext($context)->version(),
                'report' => json_encode([
                    'imported' => $imported, 'synthesized' => $synthesized,
                    'verified_keys' => count($keys),
                ], JSON_THROW_ON_ERROR),
            ]);

            return compact('imported', 'synthesized', 'keys');
        });
    } catch (\Throwable $e) {
        $output->writeln('<error>' . $e->getMessage() . '</error>');
        return self::FAILURE;
    }

    foreach ($result['synthesized'] as $key) {
        $output->writeln("<comment>synthesized archived plan '{$key}' (empty entitlements)</comment>");
    }
    $output->writeln('<info>v2 preparation complete.</info>');
    return self::SUCCESS;
}
```

Change the existing `PlanManagementService::emitAudit()` implementation to register its current emission callback with `db($this->context)->afterCommit(...)`. `Connection::afterCommit()` runs immediately outside a transaction and queues under this command's outer transaction, preserving existing callers while suppressing false success audits on rollback. Add a focused test proving outer rollback emits zero records and outer commit emits each plan audit exactly once.

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
- Create: `tests/Support/V2SubscriptionsTestCase.php`
- Test: `tests/Integration/SubjectMigrationTest.php` (new; drives `006` explicitly against a 1.x-shaped DB)

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
public function testDownRefusesWhenV2SubjectOrWorkspaceCatalogDataExists(): void
public function testDownReversesACompatibleTenantOnlyFixture(): void
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
// (4) Constrain: alter plan_uuid to NOT NULL; drop the exact create-time index
//     `subscriptions_tenant_uuid_unique` (from migration 001);
//     add UNIQUE(tenant_uuid, subject_type, subject_uuid) as uniq_subscriptions_subject;
//     overrides: drop uniq_override_tenant_entitlement, add
//     UNIQUE(tenant_uuid, subject_type, subject_uuid, entitlement) as uniq_override_subject_entitlement;
//     plans: drop the exact create-time index `subscription_plans_plan_key_unique`
//     (from migration 004), add UNIQUE(audience, owner_tenant_uuid, plan_key)
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

Index/unique drop-and-add use the exact names above. Prefer the schema builder's alter API; use a documented dialect-specific pending operation only if that API cannot express the operation.

`down()` is reversible only while the database remains representable by 1.x. Before any DDL, it must fail closed when any `subject_type <> 'tenant'` row exists, any `audience='user'`/non-platform plan exists, or any receipt cannot be derived solely from a legacy event. The exception instructs operators to restore a pre-v2 backup. A compatible tenant-only fixture reverses the constraint names, removes v2 columns/tables, and restores the two exact 1.x unique indexes. This preflight is tested; do not silently discard membership/catalog/receipt data under a "dev only" rationale.

- [ ] **Step 4:** GREEN on `SubjectMigrationTest`; create `V2SubscriptionsTestCase` extending `SubscriptionsTestCase`. Its `setUp()` calls parent first, applies `006` while the database has zero subscription rows, imports/seeds explicit platform plans, and its `seedSubscription()` override resolves and writes `plan_uuid`, `subject_type`, and `subject_uuid`. Tasks 6–8 place new v2 tests on this isolated harness. **Do not add `006` to the shared `SubscriptionsTestCase` in this task**: current production services and legacy fixture helpers cannot yet satisfy `plan_uuid NOT NULL`. The shared-harness switch is Task 9's coordinated activation boundary. Full legacy suite + migration suite + v2 harness smoke test + gates green.
- [ ] **Step 5:** Commit `feat!: subject-model migration 006 with receipts table and guarded upgrade`.

### Task 6: Subject-aware repositories + receipts repository

**Files:** Modify all four repositories; Create `src/Repositories/ProviderEventReceiptRepository.php`, `src/Repositories/UniqueViolations.php`; Tests in `tests/Integration/Repositories/` on `V2SubscriptionsTestCase`.

**Interfaces (produced):**

```php
// SubscriptionRepository
public function findBySubject(ApplicationContext $c, Subject $s): ?array;   // triple match
public function updateBySubject(ApplicationContext $c, Subject $s, array $changes): void;
// findByTenant/updateByTenant REMAIN byte-compatible in this task; Task 9 switches
// them to Subject::tenant delegates at the coordinated activation boundary.

// OverrideRepository
public function activeForSubject(ApplicationContext $c, Subject $s): array;
// activeForTenant remains byte-compatible until Task 9, then delegates via Subject::tenant.

// SubscriptionPlanRepository — every finder gains scope:
public function findByUuid(ApplicationContext $c, string $uuid): ?array;
public function findByKeyInScope(ApplicationContext $c, string $audience, string $owner, string $key): ?array;
public function findResolvableByKeyInScope(...same signature...): ?array;
public function listInScope(ApplicationContext $c, string $audience, string $owner): array;
public function maxUpdatedAtInScope(ApplicationContext $c, string $audience, string $owner): ?string;
// 1.x names remain byte-compatible through Task 8; Task 9 switches them to
// platform scope ('tenant','') after the shared schema/harness is activated.

// ProviderEventReceiptRepository
public function insertPending(ApplicationContext $c, array $row): void;     // throws on claim-unique violation
public function markAccepted(ApplicationContext $c, string $uuid, array $resolved): void;
public function markRejected(ApplicationContext $c, string $uuid, string $rejectionCode): void;
public function existsByLogicalKey(ApplicationContext $c, string $gateway, string $key): bool;
public function isUniqueViolation(\Throwable $e): bool; // extracted SHARED helper — move the existing
// SubscriptionEventRepository::isUniqueViolation body into a new
// src/Repositories/UniqueViolations.php static and delegate from both repos (spec §8's shared detector).
```

- [ ] Add repository-boundary validation to `SubscriptionEventRepository::insertOrThrow()`: construct/validate the static identity before SQL; tenant, type, and subject UUID must be non-empty; type must be exactly `tenant|user`; tenant subjects require `subject_uuid === tenant_uuid`. This validates coherence only (host existence remains `SubjectResolverInterface`'s job). Malformed events throw `InvalidArgumentException`, never enter `subscription_events`, and rejected provider attempts remain receipts only. Update every direct event fixture/helper to carry a coherent triple.
- [ ] Steps: failing tests for each new finder (triple-match semantics: `findBySubject` must NOT return a user row for `Subject::tenant`), event-boundary rejection tests, and an extraction test for `UniqueViolations::isUniqueViolation` covering the SQLite/MySQL/PostgreSQL shapes already handled by the current helper; implement on `V2SubscriptionsTestCase`; run the untouched legacy suite too; commit `feat: subject-aware repositories and provider-event receipt repository`.

### Task 7: Scoped, DB-authoritative `PlanCatalog`

**Files:** Modify `src/Catalog/PlanCatalog.php`; Tests `tests/Integration/Catalog/` (extend existing catalog tests).

**Interfaces (produced):**

```php
public static function fromContext(ApplicationContext $context): self;            // legacy overlay until Task 9
public static function forScope(ApplicationContext $context, string $audience, string $ownerTenantUuid): self;
public function audience(): string;
public function ownerTenantUuid(): string;
public function defaultPlan(): string;              // platform scope only; forScope(user,…) throws LogicException
public function planUuidForKey(string $planKey): ?string;                         // NEW: key→uuid in scope
public function entitlementsForUuid(string $planUuid): array;                     // NEW: uuid-first read
public function isAssignableUuid(string $planUuid): bool;                         // NEW
public function providerPriceIdForUuid(string $planUuid): ?string;                // NEW
// On forScope(), key-based methods resolve only DB rows within that scope.
// fromContext() preserves its 1.x config overlay until Task 9 flips it to
// forScope($context, 'tenant', '') and deletes the transitional branch.
public function graceDays(): int;                    // unchanged (config)
public function version(): string;                   // audience:owner:maxUpdatedAtInScope (no config hash)
```

- [ ] **Failing tests on `V2SubscriptionsTestCase`:** `forScope()` has no overlay (a config-only key resolves to nothing until imported); scope isolation (same key in platform and a workspace scope resolves independently; `forScope('user','w-1')` never sees platform rows); `defaultPlan()` throws outside the platform scope; scoped `version()` changes when a row updates and differs between scopes. Existing `fromContext()` overlay tests remain unedited and green in this task.
- [ ] Implement the scoped DB-authoritative path (constructor gains audience/owner; scoped methods never read config plans). Keep a narrowly marked transitional branch in `fromContext()` for legacy callers. Task 9 must remove it, make `fromContext()` return platform `forScope()`, delete the config-hash half of `version()`, and flip the existing overlay tests. Run both harnesses + gates.
- [ ] Commit `feat: add scoped DB-authoritative plan catalog path` (the breaking cutover is Task 9).

### Task 8: Scope-aware plan management + immutable `plan_key`

**Files:** Modify `src/Plans/PlanManagementService.php`, `src/Plans/PlanPayloadValidator.php`, the `src/Console/Plans/*` commands, `src/Http/PlanController.php` (platform scope pinned); Tests: extend `tests/Integration/PlanManagementServiceTest.php` + console tests.

**Interfaces (exact host-facing API):**

```php
public function createInScope(string $audience, string $ownerTenantUuid, array $payload): array;
public function updateInScope(
    string $audience,
    string $ownerTenantUuid,
    string $planKey,
    array $payload
): array;
public function archiveInScope(string $audience, string $ownerTenantUuid, string $planKey): array;
public function findInScope(string $audience, string $ownerTenantUuid, string $planKey): ?array;
public function listInScope(string $audience, string $ownerTenantUuid): array;
```

The existing `create/update/archive/find/list/importConfig` signatures remain unchanged. Through Task 8 they retain their 1.x behavior; Task 9 switches them to platform-scope delegates `('tenant', '')`. `importConfig()` is always platform-only and never accepts a scope.

- [ ] **Failing tests:** the five exact scoped methods enforce spec §2 (`tenant` requires owner `''`; `user` requires non-empty owner); **update rejects any `plan_key` change** with exact message `plan_key is immutable`; `plans:*` commands accept `--audience=` and `--owner=` defaulting to platform scope; `PlanController` can reach only platform scope; import-config writes platform rows. Keep all existing no-option/service tests green during this additive task.
- [ ] Implement and test on `V2SubscriptionsTestCase`; run the untouched legacy suite + gates; commit `feat: add scope-aware plan management and immutable plan keys`. Task 9 performs the breaking facade cutover.

### Task 9: Subject-aware `SubscriptionService` core + tenant facade

**Files:** Modify `src/SubscriptionService.php`, `src/Catalog/PlanCatalog.php`, `src/Plans/PlanManagementService.php`, the four repositories from Task 6, `tests/Support/SubscriptionsTestCase.php`, and every test fixture/direct insert found by the activation inventory; Tests: extend `tests/Integration/SubscriptionServiceTest.php` + new `tests/Integration/SubscriptionServiceSubjectTest.php`, `SubscriptionServiceFacadeTest.php`.

**Interfaces (produced):** exactly spec §7's five `…For(Subject …)` methods (with `startFor(Subject $s, string $planUuid, array $opts = [])`); the five 1.x tenant methods preserved as facades (`start()` maps `planKey` → `planUuidForKey` in the platform catalog and calls `startFor`).

Key implementation requirements (each with its own failing test first):

- [ ] **Coordinated activation boundary:** before changing code, inventory every raw write/read that assumes the old shapes:

  ```bash
  rg -n "table\(['\"](subscriptions|subscription_overrides|subscription_events|subscription_plans)['\"]\)|seedSubscription\(|INSERT INTO (subscriptions|subscription_events)" tests src
  ```

  Record the resulting file list in the commit message/checklist. Then, in one task: apply `SubjectModel` after `005` in the shared `SubscriptionsTestCase`; import/seed platform plans before subscription fixtures; update `seedSubscription()` and every direct subscription/event insert with `plan_uuid` and a coherent subject triple; switch `findByTenant/updateByTenant/activeForTenant` and legacy plan-repository methods to their subject/platform delegates; make `PlanCatalog::fromContext()` DB-authoritative platform scope and remove the Task-7 transitional overlay; switch existing plan-management methods to the Task-8 platform facades. No half-cutover commit is allowed. The old suite must first fail for the expected schema/catalog reasons, then pass after the coordinated changes.
- [ ] **Transactional lifecycle (spec §8):** every state change + its event append run inside `db($this->context)->transaction(...)` — `startFor`, `changePlanFor`, `cancelFor`, `reconcileFor` (the reconcile drift-write + event). Event rows now include the subject columns (`tenant_uuid` = subject tenantUuid, `subject_type`, `subject_uuid`).
- [ ] **Deterministic race (spec §8):** `startFor` inserts inside a transaction with the insert wrapped in a NESTED `transaction(...)` call (the framework promotes nesting to savepoints — this is the PG isolation). On `UniqueViolations::isUniqueViolation`: re-read via `findBySubject`; same `plan_uuid` → return winner row (idempotent); different `plan_uuid` → throw new `SubscriptionConflictException` (create `src/SubscriptionConflictException.php`, extends `\RuntimeException`) and change nothing. Test both outcomes by pre-inserting the winner row before calling `startFor`.
- [ ] **Subject validation (spec §4):** constructor gains `SubjectResolverInterface $subjects`; every `…For` method first requires `$this->subjects->validate($this->context, $s)` (throws `\InvalidArgumentException('invalid subject')` otherwise) and audience-matching: the plan row's `(audience, owner_tenant_uuid)` must be `('tenant','')` for tenant subjects and `('user', $s->tenantUuid)` for user subjects (test: a bound permissive fake resolver + wrong-scope plan → rejected).
- [ ] **Plan reference:** rows are written with BOTH `plan_uuid` (authoritative) and denormalized `plan_key` (read from the plan row); `changePlanFor` refreshes both + `provider_price_id` from the target plan row.
- [ ] Facade-equivalence test file: copy the strongest existing 1.x lifecycle test METHODS verbatim into `SubscriptionServiceFacadeTest.php` (per spec §11.2 — they must pass unmodified apart from the test-case import).
- [ ] Run both the now-activated shared harness and focused v2 tests, then full suite + gates; commit the activation and lifecycle atomically as `feat!: activate subject-aware lifecycle with preserved tenant facade`.

### Task 10: Receipts-first projector

**Files:** Modify `src/Projection/SubscriptionEventProjector.php`; Create `src/Projection/ProviderReceiptData.php`; Tests: extend `tests/Integration/Projection/` (+ new `ReceiptProjectionTest.php`).

Behavior (each bullet test-first; keep the existing state-machine mapping `computeChanges()` byte-identical):

- [ ] **Claim-first receipts:** `project()` opens ONE transaction; inserts a `pending` receipt (candidate identity from normalized metadata: `candidate_tenant_uuid`, `candidate_subject_type`, `candidate_subject_uuid`, `candidate_plan_uuid`; closed-projection `data`) — the receipt's `(gateway, logical_key)` unique is the FIRST claim; a duplicate loses here (existing cheap read-side early-out now checks the receipts table).
- [ ] **Receipt-data contract:** `ProviderReceiptData::sanitize(array $providerPayload): array` is the only path into `receipts.data`. Its closed allowlist is `gateway_subscription_id`, `status`, `current_period_end`, plus `metadata` restricted to `tenant_uuid`, `subject_type`, `subject_uuid`, and `plan_uuid`. Raw payloads and customer/email/billing fields are never stored. Keys matching `token|secret|password|authorization|signature|api_key|client_secret` are rejected recursively as defense in depth. Tests feed hostile nested secrets and assert they are absent while the four identity fields and lifecycle status needed for diagnosis survive.
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

**Files:** Modify `src/Console/ShowSubscriptionCommand.php`, `src/Console/SetPlanCommand.php`, `src/Console/ReconcileCommand.php`; Tests: extend `tests/Integration/Console/`.

- [ ] `--subject-type=` (`tenant`|`user`, default `tenant`) and `--subject-uuid=` on all three. Tenant mode defaults subject UUID to `--tenant`; user mode requires both non-empty `--tenant` and `--subject-uuid`. `subscriptions:set-plan` resolves within the audience-matching catalog. Invalid combinations exit non-zero with one line.
- [ ] **Reconcile-all correctness:** the no-filter branch still iterates `SubscriptionRepository::allWithProvider()`, but reconstructs each row as `new Subject($row['tenant_uuid'], $row['subject_type'], $row['subject_uuid'])` and calls `reconcileFor()`. It must never call the tenant facade for user rows. A mixed fixture (one tenant and one user subscription, same workspace allowed) proves each exact subject is reconciled once, neither is cross-targeted, and the reported count is two. `--tenant` with default type preserves the existing tenant-only CLI behavior.
- [ ] Suite + gates; commit `feat: subject options for subscription console commands`.

### Task 14: Provider wiring, config, docs

**Files:** Modify `src/SubscriptionsServiceProvider.php`, `config/subscriptions.php` (comment updates only — no key changes, spec §10), `README.md`, `docs/BRING_YOUR_OWN_PROVIDER.md`, `CHANGELOG.md`.

- [ ] `services()`: bind `SubjectResolverInterface => DefaultSubjectResolver` (shared; hosts override the binding); register `MemberEntitlementResolver`, `ProviderEventReceiptRepository`, `SubscriptionSubjectDataPurger`, `RequireMemberEntitlement`; middleware alias `require_member_entitlement`; keep every existing binding. `boot()` adds the Task-12 tenant-table registration call. Extend `ServiceProviderWiringTest` for each new id + alias.
- [ ] Docs: README gains the two-layer model table (spec §0), the metadata contract (spec §6), the upgrade path (1.4.0 → prepare → 2.0), and "binding the resolver enables memberships"; BYOP doc gains the receipt/rejection semantics.
- [ ] Suite + gates; commit `feat: 2.0 wiring, resolver binding seam, and documentation`.

### Task 15: PostgreSQL savepoint proof (env-gated)

**Files:** Create `tests/Integration/Concurrency/PostgresSavepointTest.php`, `.github/workflows/ci.yml`; Modify `README.md`.

- [ ] Test skips (`markTestSkipped`) unless `SUBSCRIPTIONS_TEST_PG_DSN` (+`_USER`/`_PASS`) env vars are set; when set, builds a `Connection` against PG, runs migrations 001–006, then inside one transaction: pre-insert a subject row, call `startFor` for the same subject (unique violation inside the savepoint), assert the OUTER transaction still commits a subsequent write (the poisoned-transaction failure mode this exists to prevent), and assert same-plan idempotency + different-plan `SubscriptionConflictException` behave identically to SQLite. Document the DSN variables in README.
- [ ] This repository has no existing GitHub workflow to mirror. Create `.github/workflows/ci.yml` explicitly: PHP 8.3, Composer install/cache, the normal PHPUnit/PHPStan/PHPCS gates, plus a PostgreSQL 16 service with health check and the three `SUBSCRIPTIONS_TEST_PG_*` variables wired to the test job. The PostgreSQL test must execute rather than report skipped; assert that in the job output/command selection. Keep the local no-DSN skip path green.
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
- **Type consistency:** `Subject`/`SubjectType`/`SubjectResolverInterface` (Task 4) are the shapes consumed by Tasks 6, 9, 10, 11, 12, 13; `UniqueViolations` (Task 6) is consumed by Tasks 9–10; `PlanCatalog::forScope`/`planUuidForKey` (Task 7) are consumed by Tasks 8–9 and 13; `ProviderReceiptData` (Task 10) is the only receipt-data writer; `SubscriptionConflictException` is defined in Task 9 and referenced in Task 15.
- **Activation consistency:** Tasks 5–8 use `V2SubscriptionsTestCase`; Task 9 alone activates `006` in the shared harness and flips legacy facades/catalog behavior. This prevents an intermediate commit with `plan_uuid NOT NULL` but old production writers.
- **Placeholder scan:** all console class names and CI files are explicit; no TBD/TODO or "verify exact name" instructions remain.
