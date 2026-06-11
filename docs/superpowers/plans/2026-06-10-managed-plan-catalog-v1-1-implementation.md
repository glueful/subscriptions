# Managed Plan Catalog v1.1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a DB-managed subscription plan catalog so non-developer operators can create, update, archive, list, and import commercial plans while preserving v1's config fallback, entitlement checker, middleware, overrides, and Payvia soft-link behavior.

**Architecture:** Introduce `subscription_plans` as the managed catalog source. `PlanCatalog` becomes the single two-source resolver: active/archived DB rows override config, draft rows do not resolve, config remains seed/fallback, and `PlanCatalog::isAssignable()` becomes the single assignment rule. HTTP and CLI write surfaces call a `PlanManagementService`, which performs validation, transactional writes, audit logging, and import-from-config.

**Tech Stack:** PHP 8.3, Glueful extension service provider, Glueful migrations, Glueful ORM/query builder, Symfony Console, Glueful router/controllers, subscriptions-owned plan-management permission guard, `PermissionManager`, PHPUnit, PHPStan, PHPCS.

---

## Context

Read first:

- `docs/superpowers/specs/2026-06-10-managed-plan-catalog-v1-1-design.md`
- `src/Catalog/PlanCatalog.php`
- `src/SubscriptionService.php`
- `src/Console/SetPlanCommand.php`
- `src/SubscriptionsServiceProvider.php`
- `tests/Support/SubscriptionsTestCase.php`

Do not reopen these locked decisions from the spec:

- Config stays as seed/fallback.
- Active and archived DB plans resolve.
- Draft DB plans do not resolve and cannot be assigned.
- `active -> draft` and `archived -> draft` are forbidden.
- Empty entitlement maps are valid and mean "deny every entitlement key."
- `import-config` is a reserved plan key.
- HTTP management routes require `subscriptions.plans.manage` and fail closed through a subscriptions-owned guard that calls `PermissionManager::can()` directly.

Framework caveat:

- Do not use framework `gate_permissions` for this feature. As of the plan date, `GateAttributeMiddleware` reads `handler_meta` and passes through when it is absent, while the router does not populate `handler_meta` for matched handlers. That is a framework-level security gap and should be fixed separately for framework 1.54.1 and the `glueful/users` routes. This feature must not depend on that fix.

---

## File Structure

Create:

- `migrations/004_CreateSubscriptionPlansTable.php`
- `src/Repositories/SubscriptionPlanRepository.php`
- `src/Plans/PlanPayloadValidator.php`
- `src/Plans/PlanManagementService.php`
- `src/Http/PlanController.php`
- `src/Http/RequirePlanManagementPermission.php`
- `routes.php`
- `src/Console/Plans/CreatePlanCommand.php`
- `src/Console/Plans/UpdatePlanCommand.php`
- `src/Console/Plans/ArchivePlanCommand.php`
- `src/Console/Plans/ImportConfigPlansCommand.php`
- `src/Console/Plans/ListPlansCommand.php`
- `tests/Integration/Repositories/SubscriptionPlanRepositoryTest.php`
- `tests/Unit/Plans/PlanPayloadValidatorTest.php`
- `tests/Integration/Catalog/ManagedPlanCatalogTest.php`
- `tests/Integration/PlanManagementServiceTest.php`
- `tests/Integration/Http/PlanControllerTest.php`
- `tests/Integration/Console/PlanCommandsTest.php`

Modify:

- `src/Catalog/PlanCatalog.php`
- `src/SubscriptionService.php`
- `src/Console/SetPlanCommand.php`
- `src/SubscriptionsServiceProvider.php`
- `composer.json`
- `tests/Support/SubscriptionsTestCase.php`
- `tests/Integration/MigrationsTest.php`
- `tests/Integration/ServiceProviderWiringTest.php`
- `tests/Integration/SubscriptionServiceTest.php`
- `README.md`
- `CHANGELOG.md`

---

## Task 1: Add `subscription_plans` Schema

- [ ] Write failing migration tests first in `tests/Integration/MigrationsTest.php`.
  - Assert `subscription_plans` exists.
  - Assert columns: `id`, `uuid`, `plan_key`, `display_name`, `description`, `entitlements`, `payvia_priced_plan_uuid`, `status`, `sort_order`, `created_at`, `updated_at`.
  - Assert unique indexes on `uuid` and `plan_key`.
  - Assert `updated_at` is indexed.

Run now -> expected FAIL because `subscription_plans` does not exist:

```bash
vendor/bin/phpunit tests/Integration/MigrationsTest.php --filter subscription_plans
```

- [ ] Create `migrations/004_CreateSubscriptionPlansTable.php`.

Use the existing migration namespace and style:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateSubscriptionPlansTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('subscription_plans')) {
            return;
        }

        $schema->createTable('subscription_plans', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('plan_key', 64);
            $table->string('display_name', 120);
            $table->string('description', 255)->nullable();
            $table->json('entitlements');
            $table->string('payvia_priced_plan_uuid', 12)->nullable();
            $table->string('status', 20);
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            $table->unique('uuid');
            $table->unique('plan_key');
            $table->index('status');
            $table->index('updated_at');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('subscription_plans');
    }

    public function getDescription(): string
    {
        return 'Create managed subscription plans table';
    }
}
```

- [ ] Update `tests/Support/SubscriptionsTestCase.php` to run `CreateSubscriptionPlansTable` after the first three migrations.

- [ ] Run:

```bash
vendor/bin/phpunit tests/Integration/MigrationsTest.php
```

Expected: migration tests pass.

Commit:

```bash
git add migrations/004_CreateSubscriptionPlansTable.php tests/Support/SubscriptionsTestCase.php tests/Integration/MigrationsTest.php
git commit -m "feat(plans): add managed subscription plans table"
```

---

## Task 2: Add Repository for Managed Plans

- [ ] Write `tests/Integration/Repositories/SubscriptionPlanRepositoryTest.php` first.

Cover:

- insert/find by `plan_key`
- active/archived rows are resolvable candidates
- draft row can be found but is not returned by the repository method used for catalog resolution
- `list()` orders by `sort_order`, then `plan_key`
- `maxUpdatedAt()` returns `null` when empty and the max timestamp when populated
- duplicate `plan_key` fails at DB level
- import/upsert does not overwrite unless forced
- no-table calls degrade cleanly for catalog use: repository methods may throw, but `PlanCatalog` must catch and fall back to config

Run now -> expected FAIL because `SubscriptionPlanRepository` does not exist:

```bash
vendor/bin/phpunit tests/Integration/Repositories/SubscriptionPlanRepositoryTest.php --filter insert
```

- [ ] Create `src/Repositories/SubscriptionPlanRepository.php`.

Required methods:

```php
final class SubscriptionPlanRepository
{
    /** @return array<string,mixed>|null */
    public function findByKey(ApplicationContext $context, string $planKey): ?array;

    /** @return array<string,mixed>|null */
    public function findResolvableByKey(ApplicationContext $context, string $planKey): ?array;

    /** @return list<array<string,mixed>> */
    public function list(ApplicationContext $context): array;

    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void;

    /** @param array<string,mixed> $changes */
    public function updateByKey(ApplicationContext $context, string $planKey, array $changes): void;

    /** @return string|null */
    public function maxUpdatedAt(ApplicationContext $context): ?string;

    public function exists(ApplicationContext $context, string $planKey): bool;
}
```

Repository rules:

- Use the existing `db($context)` / `$context` connection pattern from subscription repositories.
- JSON encode/decode `entitlements` at the repository boundary.
- Return associative arrays, not entity objects.
- Set timestamps in service code, not repository code, unless the existing repository pattern already owns timestamps.

- [ ] Run:

```bash
vendor/bin/phpunit tests/Integration/Repositories/SubscriptionPlanRepositoryTest.php
```

Expected: repository tests pass.

Commit:

```bash
git add src/Repositories/SubscriptionPlanRepository.php tests/Integration/Repositories/SubscriptionPlanRepositoryTest.php
git commit -m "feat(plans): add subscription plan repository"
```

---

## Task 3: Add Payload Validation and Status Transition Rules

- [ ] Write `tests/Unit/Plans/PlanPayloadValidatorTest.php` first.

Cover:

- valid create payload accepts `bool`, non-negative `int`, and explicit `null` entitlement values
- empty `entitlements: []` is valid
- rejects strings, floats, arrays, objects, and negative ints as entitlement values
- rejects invalid `plan_key` characters and uppercase keys
- rejects reserved `plan_key` `import-config`
- rejects create without required `plan_key`, `display_name`, `entitlements`, and `status`
- allows `draft -> active`, `draft -> archived`, `active -> archived`, `archived -> active`
- rejects `active -> draft` and `archived -> draft`
- patch cannot rename `plan_key`

Run now -> expected FAIL because `PlanPayloadValidator` does not exist:

```bash
vendor/bin/phpunit tests/Unit/Plans/PlanPayloadValidatorTest.php --filter active_to_draft
```

- [ ] Create `src/Plans/PlanPayloadValidator.php`.

Required public API:

```php
final class PlanPayloadValidator
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function validateCreate(array $payload): array;

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public function validatePatch(array $payload, array $current): array;

    /** @return array<string,mixed> */
    public function validateImportConfigPlan(string $planKey, array $configPlan, string $status): array;
}
```

Implementation notes:

- Throw `InvalidArgumentException` with actionable messages. Controllers translate to HTTP 422; CLI commands print the message and exit failure.
- Use regex `/\A[a-z0-9._-]{1,64}\z/`.
- Treat the `entitlements` payload as a map. Preserve explicit `null`.
- Normalize status to lowercase.
- Default `sort_order` to `0`.
- Default `description` and `payvia_priced_plan_uuid` to `null`.
- Do not perform DB uniqueness checks here; that belongs in service/repository.

- [ ] Run:

```bash
vendor/bin/phpunit tests/Unit/Plans/PlanPayloadValidatorTest.php
```

Expected: validator tests pass.

Commit:

```bash
git add src/Plans/PlanPayloadValidator.php tests/Unit/Plans/PlanPayloadValidatorTest.php
git commit -m "feat(plans): validate managed plan payloads"
```

---

## Task 4: Make `PlanCatalog` Two-Source

- [ ] Write `tests/Integration/Catalog/ManagedPlanCatalogTest.php` first.

Cover:

- empty `subscription_plans` table falls back to config
- active DB plan overrides config plan with the same `plan_key`
- archived DB plan overrides config and resolves
- draft DB plan does not resolve and falls back to config when config exists
- DB-only draft plan resolves to empty map
- `isAssignable()` returns true for active DB plan
- `isAssignable()` returns false for archived and draft DB plans
- `isAssignable()` returns true for config plan only when no DB row exists
- `pricedPlanUuid()` prefers active/archived DB row over config
- `version()` changes when a DB plan `updated_at` changes
- no-table fallback: instantiate `PlanCatalog::fromContext($ctx)` without running migration 004 and assert config plans still resolve and `version()` does not throw

Run now -> expected FAIL because `PlanCatalog` is still config-only:

```bash
vendor/bin/phpunit tests/Integration/Catalog/ManagedPlanCatalogTest.php --filter active_db_plan_overrides_config
```

- [ ] Modify `src/Catalog/PlanCatalog.php`.

Required shape:

```php
final class PlanCatalog
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?ApplicationContext $context = null,
        private readonly ?SubscriptionPlanRepository $plans = null,
    ) {
    }

    public static function fromContext(ApplicationContext $context): self
    {
        return new self(
            (array) config($context, 'subscriptions', []),
            $context,
            new SubscriptionPlanRepository(),
        );
    }

    /** @return array<string,mixed> */
    public function entitlementsFor(string $planKey): array;

    public function defaultPlan(): string;

    public function planExists(string $planKey): bool;

    public function isAssignable(string $planKey): bool;

    public function graceDays(): int;

    public function pricedPlanUuid(string $planKey): ?string;

    public function version(): string;
}
```

Resolution rules:

- Keep all existing public methods (`defaultPlan()`, `graceDays()`, `planExists()`, `pricedPlanUuid()`, `version()`) because v1 resolver/reconcile code depends on them.
- `entitlementsFor()` uses DB only when row status is `active` or `archived`.
- Draft DB rows are ignored for resolution and fall back to config.
- If neither source exists, return `[]`.
- `planExists()` remains broad for compatibility: true when a DB row exists in any status or config has the key. Do not use it for assignment.
- `isAssignable()` is the only assignment method:
  - active DB row: true
  - draft/archived DB row: false
  - no DB row + config row exists: true
  - no source: false
- `version()` must include config hash and DB max updated time:

```php
$dbVersion = $this->plans?->maxUpdatedAt($this->context) ?? 'none';
return substr(hash($algo, $encoded), 0, 16) . ':' . $dbVersion;
```

- If the repository/table is unavailable because migration 004 has not run yet, `entitlementsFor()`, `planExists()`, `isAssignable()`, `pricedPlanUuid()`, and `version()` must catch the database failure and behave as config-only. This protects fresh installs where the extension is enabled before migrations are applied.

- [ ] Run:

```bash
vendor/bin/phpunit tests/Integration/Catalog/ManagedPlanCatalogTest.php
vendor/bin/phpunit tests/Unit/Catalog/PlanCatalogTest.php
```

Expected: config-only catalog tests and managed catalog tests pass.

Commit:

```bash
git add src/Catalog/PlanCatalog.php tests/Integration/Catalog/ManagedPlanCatalogTest.php tests/Unit/Catalog/PlanCatalogTest.php
git commit -m "feat(plans): resolve catalog from database with config fallback"
```

---

## Task 5: Enforce Assignability in Subscription Assignment Flows

- [ ] Update `tests/Integration/SubscriptionServiceTest.php`.

Add cases:

- `start()` accepts active DB plan.
- `start()` rejects draft DB plan.
- `start()` rejects archived DB plan.
- `changePlan()` rejects draft DB plan.
- `changePlan()` rejects archived DB plan.
- config-only plan still works when there is no DB row.

Run now -> expected FAIL because `SubscriptionService` does not yet call `isAssignable()`:

```bash
vendor/bin/phpunit tests/Integration/SubscriptionServiceTest.php --filter rejects.*draft
```

- [ ] Modify `src/SubscriptionService.php`.

Before inserting/changing plan:

```php
if (!$this->catalog->isAssignable($planKey)) {
    throw new \InvalidArgumentException("Plan '{$planKey}' is not assignable.");
}
```

- [ ] Update `tests/Integration/Console/ConsoleCommandsTest.php`.

Replace current unknown-plan-only coverage with:

- `subscriptions:set-plan` accepts active DB plan.
- rejects draft DB plan with a clear message.
- rejects archived DB plan with a clear message.

- [ ] Modify `src/Console/SetPlanCommand.php`.

Replace:

```php
if (!PlanCatalog::fromContext($ctx)->planExists($plan)) {
```

with:

```php
if (!PlanCatalog::fromContext($ctx)->isAssignable($plan)) {
```

Message should say "not assignable" rather than only "unknown."

- [ ] Run:

```bash
vendor/bin/phpunit tests/Integration/SubscriptionServiceTest.php
vendor/bin/phpunit tests/Integration/Console/ConsoleCommandsTest.php
```

Expected: assignment flows reject draft/archived plans and still support config fallback.

Commit:

```bash
git add src/SubscriptionService.php src/Console/SetPlanCommand.php tests/Integration/SubscriptionServiceTest.php tests/Integration/Console/ConsoleCommandsTest.php
git commit -m "fix(plans): enforce catalog assignability"
```

---

## Task 6: Add Plan Management Service

- [ ] Write `tests/Integration/PlanManagementServiceTest.php` first.

Cover:

- create persists normalized payload and generated 12-char uuid
- duplicate `plan_key` fails cleanly
- patch updates display fields, status, Payvia link, sort order, and entitlements
- patch rejects `active -> draft` and `archived -> draft`
- archive sets status to `archived` and does not mutate `subscriptions`
- import-config creates missing config plans
- import-config does not overwrite existing DB row unless forced
- force import overwrites entitlements and Payvia link from config
- patch audit captures before/after in one transaction
- patch audit payload includes `plan_key`, `action`, `before`, `after`, and `diff`
- when PSR-3 logger is absent, service emits no hard failure

Run now -> expected FAIL because `PlanManagementService` does not exist:

```bash
vendor/bin/phpunit tests/Integration/PlanManagementServiceTest.php --filter audit
```

- [ ] Create `src/Plans/PlanManagementService.php`.

Required API:

```php
final class PlanManagementService
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function create(array $payload): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function update(string $planKey, array $payload): array;

    /** @return array<string,mixed> */
    public function archive(string $planKey): array;

    /** @return list<array<string,mixed>> */
    public function importConfig(bool $force = false, string $status = 'active'): array;

    /** @return list<array<string,mixed>> */
    public function list(): array;

    /** @return array<string,mixed>|null */
    public function find(string $planKey): ?array;
}
```

Implementation notes:

- Constructor dependencies:
  - `ApplicationContext`
  - `SubscriptionPlanRepository`
  - `PlanPayloadValidator`
- Generate extension-owned `uuid` using `Utils::generateNanoID(12)`.
- Use one timestamp helper for `created_at`/`updated_at`.
- `update()` must read current row and write changes in a single DB transaction.
- v1.1 is last-write-wins; do not add optimistic locking.
- Build audit payload in service code, after validation and before commit completes.
- Emit audit through a PSR-3 logger when available: resolve `Psr\Log\LoggerInterface::class` or `logger` from the container, then log on an `info` level with message `subscriptions.plan_changed` and context containing `plan_key`, `action`, `before`, `after`, and `diff`.
- If no PSR-3 logger is available, call `error_log()` with one compact JSON line. Do not invent an activity-log service key and do not add a new audit table.
- Archive delegates to `update($key, ['status' => 'archived'])`.
- `importConfig()` reads `config($context, 'subscriptions.plans', [])`, maps `payvia_priced_plan` to `payvia_priced_plan_uuid`, and imports `display_name` from title-cased key.

- [ ] Add `SubscriptionPlanRepository`, `PlanPayloadValidator`, and `PlanManagementService` bindings to `SubscriptionsServiceProvider::services()`.

- [ ] Run:

```bash
vendor/bin/phpunit tests/Integration/PlanManagementServiceTest.php
vendor/bin/phpunit tests/Integration/ServiceProviderWiringTest.php
```

Expected: service behavior and provider bindings pass.

Commit:

```bash
git add src/Plans/PlanManagementService.php src/SubscriptionsServiceProvider.php tests/Integration/PlanManagementServiceTest.php tests/Integration/ServiceProviderWiringTest.php
git commit -m "feat(plans): add managed plan service"
```

---

## Task 7: Add HTTP Plan Management API

- [ ] Write `tests/Integration/Http/PlanControllerTest.php` first.

Cover controller and route behavior without pretending the test harness exercises the full auth pipeline:

- list returns plans ordered by `sort_order`, then `plan_key`
- show returns one plan by dotted key such as `team.pro`
- create validates payload and returns created row
- patch rejects `active -> draft` and `archived -> draft`
- archive keeps tenant subscriptions unchanged
- `import-config` route is not captured by `{key}`
- `RequirePlanManagementPermission` returns 403 when no authenticated user is present
- `RequirePlanManagementPermission` returns 403 when no permission provider is bound (verify fail-closed)
- `RequirePlanManagementPermission` returns 403 when `PermissionManager::can()` denies
- `RequirePlanManagementPermission` calls the next handler only when `PermissionManager::can($userUuid, 'subscriptions.plans.manage', 'subscriptions.plans', $context)` allows

Run now -> expected FAIL because neither the controller nor the explicit permission middleware exists:

```bash
vendor/bin/phpunit tests/Integration/Http/PlanControllerTest.php --filter permission
```

- [ ] Create `src/Http/PlanController.php`.

Follow the Payvia controller pattern: extend `Glueful\Controllers\BaseController`, accept `ApplicationContext` first in the constructor, call `parent::__construct($context)`, and resolve `PlanManagementService` through `app($context, PlanManagementService::class)` when not injected.

```php
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;

final class PlanController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private ?PlanManagementService $plans = null,
    ) {
        parent::__construct($context);
        $this->plans = $this->plans ?? app($context, PlanManagementService::class);
    }

    public function index(Request $request): mixed;

    public function show(Request $request, string $key): mixed;

    public function store(Request $request): mixed;

    public function update(Request $request, string $key): mixed;

    public function archive(Request $request, string $key): mixed;

    public function importConfig(Request $request): mixed;
}
```

Controller rules:

- Translate `InvalidArgumentException` to HTTP 422.
- Return 404 when plan is absent.
- Do not expose stack traces or raw exception messages except validation messages.
- Preserve explicit `null` in entitlement JSON.

- [ ] Create `src/Http/RequirePlanManagementPermission.php`.

This middleware is the enforcement point for v1.1. It must not rely on `handler_meta` or `gate_permissions`.

Required behavior:

- Read `auth.user` from request attributes after `auth` middleware.
- If absent, return 403.
- Resolve `permission.manager` or `Glueful\Permissions\PermissionManager` from the container.
- If unavailable, return 403.
- Call `can($user->id(), 'subscriptions.plans.manage', 'subscriptions.plans', $context)`.
- If false, return 403.
- Only call `$next($request)` when allowed.

- [ ] Create `routes.php`.

Register collection action before keyed routes:

```php
<?php

declare(strict_types=1);

use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Routing\Router;

/** @var Router $router */

$router->group(['prefix' => '/subscriptions/plans'], function (Router $router): void {
    $middleware = ['auth', 'subscriptions_plans_manage'];

    $router->get('', [PlanController::class, 'index'])->middleware($middleware)->name('subscriptions.plans.index');
    $router->post('', [PlanController::class, 'store'])->middleware($middleware)->name('subscriptions.plans.store');
    $router->post('/import-config', [PlanController::class, 'importConfig'])
        ->middleware($middleware)
        ->name('subscriptions.plans.import_config');

    $router->get('/{key}', [PlanController::class, 'show'])
        ->where('key', '[a-z0-9._-]+')
        ->middleware($middleware)
        ->name('subscriptions.plans.show');

    $router->patch('/{key}', [PlanController::class, 'update'])
        ->where('key', '[a-z0-9._-]+')
        ->middleware($middleware)
        ->name('subscriptions.plans.update');

    $router->post('/{key}/archive', [PlanController::class, 'archive'])
        ->where('key', '[a-z0-9._-]+')
        ->middleware($middleware)
        ->name('subscriptions.plans.archive');
});
```

- [ ] Update `SubscriptionsServiceProvider::boot()` to call:

```php
$this->loadRoutesFrom(__DIR__ . '/../routes.php');
```

Do this in `boot()` with the existing command discovery/event listener pattern.

- [ ] Add `PlanController::class` and `RequirePlanManagementPermission::class` to `SubscriptionsServiceProvider::services()`.

Controller binding must mirror Payvia controller bindings:

```php
PlanController::class => [
    'class' => PlanController::class,
    'shared' => true,
    'autowire' => true,
],
RequirePlanManagementPermission::class => [
    'class' => RequirePlanManagementPermission::class,
    'shared' => true,
    'autowire' => true,
    'alias' => ['subscriptions_plans_manage'],
],
```

- [ ] Add the alias to `middlewareAliases()`:

```php
'subscriptions_plans_manage' => RequirePlanManagementPermission::class,
```

- [ ] Run:

```bash
vendor/bin/phpunit tests/Integration/Http/PlanControllerTest.php
vendor/bin/phpunit tests/Integration/ServiceProviderWiringTest.php
```

Expected: route/controller wiring passes, controller resolves from the container, and the explicit permission middleware fails closed.

Commit:

```bash
git add src/Http/PlanController.php routes.php src/SubscriptionsServiceProvider.php tests/Integration/Http/PlanControllerTest.php tests/Integration/ServiceProviderWiringTest.php
git commit -m "feat(plans): expose permission-gated plan management api"
```

---

## Task 8: Add Plan Management CLI Commands

- [ ] Write `tests/Integration/Console/PlanCommandsTest.php` first.

Cover:

- `subscriptions:plans:create` creates an active plan from inline entitlement JSON
- create accepts entitlement JSON from `--entitlements-file`
- create rejects invalid entitlement value
- `subscriptions:plans:update` updates entitlements and rejects `active -> draft`
- `subscriptions:plans:update` rejects `archived -> draft`
- `subscriptions:plans:archive` archives a plan
- `subscriptions:plans:import-config` creates missing config plans
- import without `--force` does not overwrite
- import with `--force` overwrites
- `subscriptions:plans:list` prints key, display name, status, Payvia link, updated time

Run now -> expected FAIL because plan commands do not exist:

```bash
vendor/bin/phpunit tests/Integration/Console/PlanCommandsTest.php --filter active_to_draft
```

- [ ] Create commands:

```text
src/Console/Plans/CreatePlanCommand.php
src/Console/Plans/UpdatePlanCommand.php
src/Console/Plans/ArchivePlanCommand.php
src/Console/Plans/ImportConfigPlansCommand.php
src/Console/Plans/ListPlansCommand.php
```

Command names:

```text
subscriptions:plans:create
subscriptions:plans:update
subscriptions:plans:archive
subscriptions:plans:import-config
subscriptions:plans:list
```

Common rules:

- Resolve `PlanManagementService` from `app($ctx, PlanManagementService::class)`.
- Catch `InvalidArgumentException`, print the message, and return `self::FAILURE`.
- Keep output script-friendly and concise.

Create options:

```text
--key=pro
--name="Pro"
--description="For growing teams"
--status=active
--payvia-priced-plan=...
--sort-order=20
--entitlements='{"reports.export":true}'
--entitlements-file=/path/to/entitlements.json
```

Update options:

```text
--key=pro
--name=...
--description=...
--status=archived
--payvia-priced-plan=...
--sort-order=...
--entitlements=...
--entitlements-file=...
```

Archive options:

```text
--key=pro
```

Import options:

```text
--force
--status=active
```

- [ ] Run:

```bash
vendor/bin/phpunit tests/Integration/Console/PlanCommandsTest.php
vendor/bin/phpunit tests/Integration/Console/ConsoleCommandsTest.php
```

Expected: new commands pass and existing ops commands remain green.

Commit:

```bash
git add src/Console/Plans tests/Integration/Console/PlanCommandsTest.php
git commit -m "feat(plans): add managed plan console commands"
```

---

## Task 9: Documentation and Changelog

- [ ] Update `README.md`.

Add:

- DB-managed catalog overview
- config fallback/seed behavior
- empty DB table and wiped DB table behavior
- archived vs draft semantics
- empty entitlement map meaning
- HTTP route list and permission requirement
- CLI examples
- `subscriptions:plans:import-config`

- [ ] Update `CHANGELOG.md`.

Add release note section:

```markdown
## 1.1.0 - 2026-06-10

### Added
- Managed subscription plan catalog with DB-backed plans, config fallback, HTTP management API, and CLI commands.

### Changed
- Plan resolution now prefers active/archived DB plans over config plans while keeping config as seed/fallback.
```

- [ ] Update `composer.json`.

Set both the package version field, if present, and `extra.glueful.version` to `1.1.0`.

- [ ] Run ASCII check:

```bash
LC_ALL=C grep -RIn '[^ -~]' README.md CHANGELOG.md docs/superpowers/plans/2026-06-10-managed-plan-catalog-v1-1-implementation.md docs/superpowers/specs/2026-06-10-managed-plan-catalog-v1-1-design.md || true
```

Expected: no output.

Commit:

```bash
git add README.md CHANGELOG.md composer.json docs/superpowers/plans/2026-06-10-managed-plan-catalog-v1-1-implementation.md
git commit -m "docs(plans): document managed catalog operations"
```

---

## Task 10: Final Verification

- [ ] Run focused tests:

```bash
vendor/bin/phpunit tests/Integration/MigrationsTest.php
vendor/bin/phpunit tests/Integration/Repositories/SubscriptionPlanRepositoryTest.php
vendor/bin/phpunit tests/Unit/Plans/PlanPayloadValidatorTest.php
vendor/bin/phpunit tests/Integration/Catalog/ManagedPlanCatalogTest.php
vendor/bin/phpunit tests/Integration/SubscriptionServiceTest.php
vendor/bin/phpunit tests/Integration/PlanManagementServiceTest.php
vendor/bin/phpunit tests/Integration/Http/PlanControllerTest.php
vendor/bin/phpunit tests/Integration/Console/PlanCommandsTest.php
vendor/bin/phpunit tests/Integration/Console/ConsoleCommandsTest.php
```

- [ ] Run full package checks:

```bash
composer test
composer run phpcs
composer run analyze
```

- [ ] Run stale-reference greps:

```bash
rg -n "planExists\\(" src tests
rg -n "gate_permissions|handler_meta|active -> draft|archived -> draft|import-config|isAssignable|subscription_plans" docs README.md src tests migrations
```

Expected:

- `planExists()` remains only in `PlanCatalog` and tests that intentionally cover broad existence. Assignment paths use `isAssignable()`.
- `gate_permissions` and `handler_meta` do not appear in subscriptions v1.1 route enforcement code; they may appear only in explanatory docs that reference the framework follow-up.
- Docs and code mention `import-config` as reserved and route-safe.
- `subscription_plans` appears in migration, repository, catalog tests, and docs.

- [ ] Check worktree:

```bash
git status --short
```

Expected: only intended files changed/untracked. Do not stage unrelated v1 spec/plan edits unless this work intentionally updates them.

---

## Acceptance Criteria

- [ ] Config-only deployments continue working with an empty `subscription_plans` table.
- [ ] Config-only deployments continue working when migration 004 has not run yet.
- [ ] DB active plans override config plans with the same `plan_key`.
- [ ] DB archived plans override config and keep resolving for existing tenants.
- [ ] DB draft plans do not resolve.
- [ ] API and CLI reject `active -> draft` and `archived -> draft`.
- [ ] Undefined entitlement keys deny; explicit `null` grants unlimited.
- [ ] Empty entitlement maps are accepted and documented as deny-everything plans.
- [ ] API and CLI reject invalid entitlement values.
- [ ] `PlanCatalog::isAssignable()` is used by `SubscriptionService`, `subscriptions:set-plan`, and new operator assignment paths.
- [ ] `import-config` is rejected as a `plan_key`.
- [ ] `{key}` routes constrain dots with `[a-z0-9._-]+`.
- [ ] Archive does not mutate tenant subscriptions.
- [ ] PATCH audit reads before state and writes after state in one transaction.
- [ ] `import-config` seeds plans without overwriting unless forced.
- [ ] Plan edits change the catalog version fingerprint.
- [ ] `subscription_plans.updated_at` is indexed.
- [ ] The extension provider loads v1.1 plan-management routes.
- [ ] HTTP write routes use `auth` + `subscriptions_plans_manage`; the middleware calls `PermissionManager::can()` directly and fails closed with no user, no manager, no provider, or denied permission.
- [ ] `PlanController` is registered in `services()` and resolves through the container.
- [ ] Existing checker, middleware, rate-tier bridge, Payvia listener, and overrides remain DB-plan unaware.
