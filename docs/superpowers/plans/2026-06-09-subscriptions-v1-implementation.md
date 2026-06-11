# Subscriptions v1 Implementation Plan

**Status:** Executed and verified. The implementation passes PHPUnit, PSR-12 phpcs, PHPStan, Composer validation, and PHP syntax checks.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the greenfield `glueful/subscriptions` extension -- a config-driven tenant subscription lifecycle and entitlement-resolution layer that exposes `EntitlementCheckerInterface`, works fully with no `glueful/payvia` and no `glueful/tenancy` installed, and projects Payvia provider events onto tenant subscription state when Payvia is present.

**Architecture:** Entitlements decouple from payment: a tenant's effective entitlement map is resolved from a config catalog plus per-tenant overrides, gated by the subscription's status -- never from a live payment object. The entitlement contract (`Glueful\Entitlements\Contracts\EntitlementCheckerInterface`) and its allow-all `NullEntitlementChecker` default now live in **framework core**; this package binds a DB+config-backed `DefaultEntitlementChecker` **over** the core contract (overriding the Null default), provides an `EntitlementTierResolver` rate-limit bridge, three tables (`subscriptions`, `subscription_overrides`, `subscription_events`), a `RequireEntitlement` route middleware, a `SubscriptionService` lifecycle API, a conditionally-registered Payvia event listener, and reconcile/ops CLI. Soft deps are guarded everywhere with `class_exists` / nullable resolution so the package degrades gracefully.

**Tech Stack:** PHP 8.3+, Glueful framework (^1.54.0 -- the release that ships the `Glueful\Entitlements` seam), PHPUnit; soft deps glueful/tenancy + glueful/payvia

**Depends on:** the framework release that promotes the entitlement seam (the **Container precedence fix** + **Entitlement seam** framework plans). This package *consumes* the core `EntitlementCheckerInterface` and *overrides* the core `NullEntitlementChecker` default via the container-precedence fix -- so it executes only **after** that framework release ships. Pin the floor to that version (shown here as `^1.54.0`).

---

## Conventions (apply to every task)

- Every class: `declare(strict_types=1);` and `final` unless it is an `interface`.
- Namespaces: extension internals under `Glueful\Extensions\Subscriptions\`. The entitlement contract + Null default are consumed from framework core (`Glueful\Entitlements\`) -- this package does NOT define them and has NO second PSR-4 root (S-ns reversed by core promotion). Tests under `Glueful\Extensions\Subscriptions\Tests\`.
- `uuid` columns are 12-char NanoIDs generated via `Glueful\Helpers\Utils::generateNanoID(12)`. `tenant_uuid` is an opaque external id widened to `string(64)`, indexed, **no FK** (S-id).
- ASCII only -- use `--` for dashes and `->` for arrows in prose and comments.
- DB access uses the framework helpers `db($context)` (returns `Glueful\Database\Connection`) and `config($context, 'key', $default)`; cache uses the `Glueful\Cache\CacheStore` binding resolved *optionally* from the container (absent driver -> resolve uncached).
- Migrations implement `Glueful\Database\Migrations\MigrationInterface` (methods `up(SchemaBuilderInterface)`, `down(SchemaBuilderInterface)`, `getDescription()`); column/index helpers per `Glueful\Database\Schema\Builders\TableBuilder` (`->string()`, `->json()`, `->timestamp()`, `->bigInteger()->primary()->autoIncrement()`, `->unique($cols, $name)`, `->index($cols, $name)`). A UNIQUE index that includes a nullable column permits multiple NULLs on MySQL/Postgres/SQLite -- this is how the `(payvia_gateway, payvia_logical_event_key)` event dedupe and the `(payvia_gateway, payvia_subscription_id)` lookup uniqueness are DB-enforced while free/comp rows (all-NULL) stay unconstrained (S6).
- Console commands extend `Glueful\Console\BaseCommand`, carry `#[\Symfony\Component\Console\Attribute\AsCommand(name: ..., description: ...)]`, get the context via `$this->getContext()`, and use `$this->info()` / `$this->error()` / `$this->table()`.
- Tests extend a local `SubscriptionsTestCase` (PHPUnit `TestCase` + in-memory SQLite `Connection` harness, modeled on tenancy's `tests/Support/TenancyTestCase.php`). Run an individual test with the exact filter shown in each task.

---

## File Structure

### Create -- package skeleton
- `composer.json` -- `glueful/subscriptions`; `require` php only; `require-dev` `glueful/framework ^1.54.0` + phpunit/phpcs/phpstan; `suggest` tenancy + payvia; single PSR-4 root; classmap migrations; `extra.glueful`; version `1.0.0`.
- `phpunit.xml` -- test suites (Unit + Integration), bootstrap autoload.
- `.gitignore` -- vendor, cache, lock (mirror tenancy; lock NOT ignored if tenancy ships it -- match tenancy which commits `composer.lock`).
- `config/subscriptions.php` -- catalog + grace + cache + `permissive_middleware` config stub.
- `src/SubscriptionsServiceProvider.php` -- provider (services/register/boot).

### Consumed from framework core (NOT created here)
- `Glueful\Entitlements\Contracts\EntitlementCheckerInterface` + `Glueful\Entitlements\NullEntitlementChecker` -- shipped by the framework entitlement-seam release; this package binds `DefaultEntitlementChecker` over the contract.

### Create -- schema
- `migrations/001_CreateSubscriptionsTable.php`
- `migrations/002_CreateSubscriptionOverridesTable.php`
- `migrations/003_CreateSubscriptionEventsTable.php`

### Create -- resolution + checker
- `src/Catalog/PlanCatalog.php` -- reads `config('subscriptions')`, exposes `defaultPlan()`, `entitlementsFor(planKey)`, `version()`, `graceDays()`.
- `src/Repositories/SubscriptionRepository.php` -- DB reads/writes for `subscriptions`.
- `src/Repositories/OverrideRepository.php` -- DB reads for `subscription_overrides`.
- `src/Resolution/EffectivePlanResolver.php` -- status -> effective plan_key (S2/S11).
- `src/Resolution/EntitlementResolver.php` -- builds the resolved entitlement map (catalog + overrides), with cache (S12).
- `src/DefaultEntitlementChecker.php` -- implements `EntitlementCheckerInterface` via the resolver (S3 semantics).

### Create -- middleware + current-tenant convenience
- `src/Tenant/CurrentTenant.php` -- reads tenancy's `TenantContext` when present; null-safe (S4 asymmetry).
- `src/Http/RequireEntitlement.php` -- `require_entitlement` route middleware + `#[RequireEntitlement]` attribute (fail-closed 403 default; `permissive_middleware` opt-in).

### Create -- rate-limit bridge (the core-seam consumer)
- `src/RateLimiting/EntitlementTierResolver.php` -- implements the framework's `Glueful\Api\RateLimiting\Contracts\TierResolverInterface`; reads the current tenant + entitlements and returns a rate tier, bound over the framework's default `TierResolver` (last-wins, enabled by the container-precedence fix). Inert/delegating when tenancy or rate limiting is absent. (Mapping decision in Task 5.3.)

### Create -- lifecycle + reconcile
- `src/Repositories/SubscriptionEventRepository.php` -- append events with DB-enforced logical-key dedupe.
- `src/SubscriptionService.php` -- `current/start/changePlan/cancel/reconcile`.
- `src/Listeners/PaymentProviderEventListener.php` -- projects Payvia `PaymentProviderEvent` onto subscription state (conditional).
- `src/Console/ReconcileCommand.php` -- `subscriptions:reconcile [--tenant=]`.
- `src/Console/ShowSubscriptionCommand.php` -- `subscriptions:show --tenant=`.
- `src/Console/SetPlanCommand.php` -- `subscriptions:set-plan --tenant= --plan=`.

### Create -- docs
- `README.md` -- overview, install, usage, "consumes Payvia" section.
- `CHANGELOG.md` -- `1.0.0` entry.
- `docs/USAGE.md` -- entitlement checks, middleware, lifecycle, reconcile, soft-dep behavior.

### Create -- test support
- `tests/Support/SubscriptionsTestCase.php` -- in-memory SQLite harness (runs the 3 migrations; tiny PSR-11 container exposing `database`/`Connection::class`).
- `tests/Support/FakePaymentProviderEvent.php` + `tests/Support/FakePaymentProviderEvent`-style fakes for the Payvia contract (in-suite fakes so tests do not depend on payvia being installed).

---

## Phase 1 -- Package skeleton

### Task 1.1 -- composer.json + autoload + extra.glueful

**Files:**
- Create: `composer.json`

Steps:
- [ ] Create `composer.json` with `"name": "glueful/subscriptions"`, `"type": "glueful-extension"`, `"license": "MIT"`, `"version"` omitted at top level (version lives in `extra.glueful`, matching tenancy), `minimum-stability: stable`, `prefer-stable: true`.
- [ ] Set `"require": { "php": "^8.3" }` (php only -- soft deps are NOT required).
- [ ] Set `"require-dev": { "glueful/framework": "^1.54.0", "phpunit/phpunit": "^10.5", "squizlabs/php_codesniffer": "^3.6", "phpstan/phpstan": "^1.0" }` (^1.54.0 = the framework release that ships the `Glueful\Entitlements` seam; pin to whatever that release number is).
- [ ] Set `"suggest": { "glueful/tenancy": "Resolve the current tenant for RequireEntitlement / the rate-limit tier bridge.", "glueful/payvia": "Link priced plans and project provider subscription events onto subscription status." }`.
- [ ] Set a single PSR-4 root: `"autoload": { "psr-4": { "Glueful\\Extensions\\Subscriptions\\": "src/" }, "classmap": ["migrations/"] }`. (No `Glueful\Entitlements\` root -- the contract is consumed from framework core; S-ns reversed.)
- [ ] Set `"autoload-dev": { "psr-4": { "Glueful\\Extensions\\Subscriptions\\Tests\\": "tests/" } }`.
- [ ] Set `"scripts": { "test": "vendor/bin/phpunit", "phpcs": "vendor/bin/phpcs --standard=PSR12 src", "phpcbf": "vendor/bin/phpcbf --standard=PSR12 src", "analyze": "vendor/bin/phpstan analyse src" }`.
- [ ] Set `extra.glueful`: `name`/`displayName`/`description` "Subscriptions", `"version": "1.0.0"`, `"categories": ["billing", "subscriptions"]`, `"publisher": "glueful-team"`, `"provider": "Glueful\\Extensions\\Subscriptions\\SubscriptionsServiceProvider"`, `"requires": { "glueful": ">=1.54.0", "extensions": [] }`.
- [ ] Set `"config": { "sort-packages": true }`.
- [ ] **Verify:** run `php -r "json_decode(file_get_contents('composer.json')); echo json_last_error_msg();"` -- expect `No error`.
- [ ] **Commit (implementer):** `feat(skeleton): composer manifest (single PSR-4 root, framework ^1.54.0 entitlement-seam floor, soft deps)`.

### Task 1.2 -- phpunit.xml + .gitignore

**Files:**
- Create: `phpunit.xml`, `.gitignore`

Steps:
- [ ] Create `phpunit.xml` modeled on tenancy's: bootstrap `vendor/autoload.php`, two `<testsuite>`s `Unit` (`tests/Unit`) and `Integration` (`tests/Integration`), `colors="true"`, cache dir `.phpunit.cache`.
- [ ] Create `.gitignore` ignoring `/vendor/`, `/.phpunit.cache/`, `composer.lock` left tracked to mirror tenancy (or ignored -- match tenancy's `.gitignore` exactly; read it first).
- [ ] **Commit (implementer):** `chore: phpunit config + gitignore`.

### Task 1.3 -- config/subscriptions.php stub (S5)

**Files:**
- Create: `config/subscriptions.php`

Steps:
- [ ] Create `config/subscriptions.php` returning the catalog from the spec verbatim: `default_plan => 'free'`; `plans` with `free` (`reports.export=false`, `projects.limit=3`, `team.limit=1`) and `pro` (`payvia_priced_plan=null`, `reports.export=true`, `projects.limit=50`, `team.limit=20`, `api.monthly=100000`); `grace_days => 3`; `cache => ['enabled' => true, 'ttl' => 300]`.
- [ ] Add `'permissive_middleware' => false` (S4 opt-in; default fail-closed).
- [ ] Add `'reconcile' => ['schedule_enabled' => false]` (S10 -- documented hook, default off).
- [ ] **Verify:** `php -l config/subscriptions.php` -- expect `No syntax errors`.
- [ ] **Commit (implementer):** `feat(config): subscriptions catalog stub with grace, cache, permissive flag`.

### Task 1.4 -- SubscriptionsServiceProvider skeleton

**Files:**
- Create: `src/SubscriptionsServiceProvider.php`
- Create: `tests/Unit/ServiceProviderShapeTest.php`

Steps:
- [ ] Write failing test `tests/Unit/ServiceProviderShapeTest.php`: assert `SubscriptionsServiceProvider::services()` is an array and (for now) returns `[]`; assert the class extends `Glueful\Extensions\ServiceProvider`.
- [ ] Run `vendor/bin/phpunit --filter=ServiceProviderShapeTest` -- expect **FAIL** (class missing).
- [ ] Create `src/SubscriptionsServiceProvider.php` extending `Glueful\Extensions\ServiceProvider`. Implement `getName(): string` ("Subscriptions"), `getDescription(): string`, `getVersion(): string` (read `extra.glueful.version` from `../composer.json`, cached static, mirroring `PayviaServiceProvider::composerVersion()`).
- [ ] Implement `public static function services(): array` returning `[]` for now (filled in Phase 7).
- [ ] Implement `public function register(ApplicationContext $context): void` calling `$this->mergeConfig('subscriptions', require __DIR__ . '/../config/subscriptions.php')` and `$this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEPENDENT, 'glueful/subscriptions')` (S9). Import `Glueful\Database\Migrations\MigrationPriority` and `Glueful\Bootstrap\ApplicationContext`.
- [ ] Implement `public function boot(ApplicationContext $context): void` as a no-op for now (filled in Phases 6 + 7).
- [ ] Run `vendor/bin/phpunit --filter=ServiceProviderShapeTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(provider): SubscriptionsServiceProvider skeleton (mergeConfig + DEPENDENT migrations)`.

---

## Phase 2 -- Plan catalog (S5)

> **`EntitlementCheckerInterface` and `NullEntitlementChecker` are NOT defined here.** As of the framework entitlement-seam release they live in core (`Glueful\Entitlements\`). This package *consumes* the core contract and binds `DefaultEntitlementChecker` over it (Task 4.4) -- which overrides core's Null default (Task 7.1), enabled by the container-precedence fix. The contract-shape and Null-default unit tests belong to the framework plans, not here. So Phase 2 is just the plan catalog.

### Task 2.1 -- PlanCatalog

**Files:**
- Create: `src/Catalog/PlanCatalog.php`
- Create: `tests/Unit/Catalog/PlanCatalogTest.php`

Steps:
- [ ] Write failing test (uses an `ApplicationContext` with an in-memory container that returns the catalog array via `config()`; simplest path: construct `PlanCatalog` from a plain config array rather than the context, so it is pure/unit-testable). Assert: `defaultPlan()` returns `'free'`; `entitlementsFor('free')` returns `['reports.export' => false, 'projects.limit' => 3, 'team.limit' => 1]`; `entitlementsFor('pro')['api.monthly']` is `100000`; `entitlementsFor('missing')` returns `[]`; `graceDays()` returns `3`; `version()` returns a stable non-empty string for identical config and a *different* string when the plans array changes.
- [ ] Run `vendor/bin/phpunit --filter=PlanCatalogTest` -- expect **FAIL**.
- [ ] Create `src/Catalog/PlanCatalog.php`: constructor takes `array $config` (the resolved `subscriptions` config). Provide a static `fromContext(ApplicationContext $context): self` that reads `config($context, 'subscriptions', [])`.
  - `defaultPlan(): string` -> `$this->config['default_plan'] ?? 'free'`.
  - `entitlementsFor(string $planKey): array` -> `$this->config['plans'][$planKey]['entitlements'] ?? []`.
  - `graceDays(): int` -> `(int)($this->config['grace_days'] ?? 0)`.
  - `pricedPlanUuid(string $planKey): ?string` -> `$this->config['plans'][$planKey]['payvia_priced_plan'] ?? null`.
  - `version(): string` -> `substr(hash('xxh128', json_encode($this->config['plans'] ?? [])), 0, 16)` (catalog fingerprint for the cache key, S12). Use `hash('xxh128', ...)`; if unavailable fall back to `'sha256'`.
- [ ] Run `vendor/bin/phpunit --filter=PlanCatalogTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(catalog): PlanCatalog config reader with version fingerprint`.

---

## Phase 3 -- Schema (S1, S9, S-id)

### Task 3.1 -- subscriptions migration

**Files:**
- Create: `migrations/001_CreateSubscriptionsTable.php`
- Create: `tests/Support/SubscriptionsTestCase.php`
- Create: `tests/Integration/MigrationsTest.php`

Steps:
- [ ] Create `tests/Support/SubscriptionsTestCase.php` modeled on tenancy's `TenancyTestCase`: build a `Glueful\Database\Connection` against in-memory SQLite (`engine sqlite`, `sqlite.primary => ':memory:'`, `pooling.enabled => false`); run all three migration `up()`s against `getSchemaBuilder()` (reference them once they exist -- for this task wire only migration 001, add 002/003 in their tasks); wrap the connection in a PSR-11 container exposing `'database'` and `Connection::class`; build an `ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing')` and `setContainer(...)`. Expose `appContext()` and `connection()`. Add a helper `seedSubscription(array $overrides): array` that inserts a row into `subscriptions` (generating `uuid` via `Utils::generateNanoID(12)`) and returns it.
- [ ] Write failing test `tests/Integration/MigrationsTest.php::testSubscriptionsTableExists`: assert `connection()->getSchemaBuilder()->hasTable('subscriptions')` is true after setUp.
- [ ] Run `vendor/bin/phpunit --filter=MigrationsTest` -- expect **FAIL** (migration class missing).
- [ ] Create `migrations/001_CreateSubscriptionsTable.php` -- namespace `Glueful\Extensions\Subscriptions\Database\Migrations` (mirror payvia), implement `MigrationInterface`. In `up()` guard with `if ($schema->hasTable('subscriptions')) return;` then `createTable('subscriptions', ...)`:
  - `bigInteger('id')->primary()->autoIncrement()`
  - `string('uuid', 12)` + `unique('uuid')`
  - `string('tenant_uuid', 64)` + `unique('tenant_uuid')` (S1 -- one current subscription per tenant; uniqueness also indexes it)
  - `string('plan_key', 64)`
  - `string('status', 20)->default('active')`
  - `timestamp('trial_ends_at')->nullable()`, `timestamp('current_period_end')->nullable()`, `timestamp('grace_ends_at')->nullable()`, `timestamp('canceled_at')->nullable()`
  - `string('payvia_gateway', 50)->nullable()`, `string('payvia_customer_id', 191)->nullable()`, `string('payvia_subscription_id', 191)->nullable()`, `string('payvia_priced_plan_uuid', 12)->nullable()`
  - `json('metadata')->nullable()`
  - `timestamp('created_at')->default('CURRENT_TIMESTAMP')`, `timestamp('updated_at')->nullable()`
  - `unique(['payvia_gateway', 'payvia_subscription_id'], 'uniq_subscriptions_payvia_sub')` -- one subscription per **(gateway, provider-subscription)**; also indexes the `(payvia_gateway, payvia_subscription_id)` lookup path. Composite unique permits multiple all-NULL rows (free/comp subs with no payvia), but enforces uniqueness when both are set (so a `sub_X` on `stripe` and a `sub_X` on `paystack` are distinct rows).
  - `down()` -> `dropTableIfExists('subscriptions')`; `getDescription()` returns a sentence.
- [ ] Run `vendor/bin/phpunit --filter=MigrationsTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(schema): subscriptions table (unique tenant_uuid, nullable payvia_*, indexed payvia_subscription_id)`.

### Task 3.2 -- subscription_overrides migration

**Files:**
- Create: `migrations/002_CreateSubscriptionOverridesTable.php`
- Modify: `tests/Support/SubscriptionsTestCase.php` (run migration 002)
- Modify: `tests/Integration/MigrationsTest.php`

Steps:
- [ ] Add failing assertion `testOverridesTableAndUniqueKey`: table `subscription_overrides` exists; inserting two rows with the same `(tenant_uuid, entitlement)` throws (DB unique violation -- catch the PDO/QueryException and assert it was thrown).
- [ ] Run `vendor/bin/phpunit --filter=MigrationsTest` -- expect **FAIL**.
- [ ] Create `migrations/002_CreateSubscriptionOverridesTable.php`: `id` pk; `string('uuid', 12)` unique; `string('tenant_uuid', 64)` + `index('tenant_uuid', 'idx_overrides_tenant')`; `string('entitlement', 128)`; `json('value')`; `timestamp('expires_at')->nullable()`; `string('reason', 255)->nullable()`; `created_at`/`updated_at`; `unique(['tenant_uuid', 'entitlement'], 'uniq_override_tenant_entitlement')`. `down()`/`getDescription()`.
- [ ] Register migration 002 in `SubscriptionsTestCase::setUp()`.
- [ ] Run `vendor/bin/phpunit --filter=MigrationsTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(schema): subscription_overrides (unique tenant_uuid+entitlement)`.

### Task 3.3 -- subscription_events migration (DB-enforced dedupe, S6)

**Files:**
- Create: `migrations/003_CreateSubscriptionEventsTable.php`
- Modify: `tests/Support/SubscriptionsTestCase.php` (run migration 003)
- Modify: `tests/Integration/MigrationsTest.php`

Steps:
- [ ] Add failing assertions: (a) `subscription_events` exists; (b) inserting two rows with the **same non-null** `(payvia_gateway, payvia_logical_event_key)` throws (unique violation); (c) inserting **two rows with `payvia_logical_event_key = null`** both succeed (multiple NULLs allowed); (d) the **same** `payvia_logical_event_key` under **different** `payvia_gateway` values both succeed (dedupe is per-gateway) -- this is the critical idempotency guarantee.
- [ ] Run `vendor/bin/phpunit --filter=MigrationsTest` -- expect **FAIL**.
- [ ] Create `migrations/003_CreateSubscriptionEventsTable.php`: `id` pk; `string('uuid', 12)` unique; `string('tenant_uuid', 64)`; `string('type', 40)`; `string('from_status', 20)->nullable()`; `string('to_status', 20)->nullable()`; `string('source', 20)`; `string('payvia_gateway', 50)->nullable()`; `string('payvia_logical_event_key', 191)->nullable()` + `unique(['payvia_gateway', 'payvia_logical_event_key'], 'uniq_event_gateway_logical_key')` (composite unique including nullable columns -> multiple all-NULL rows permitted for manual/reconcile events; one row per **(gateway, logical event)** enforced -- dedupe is per-gateway, since the same provider logical key can recur across gateways); `json('data')->nullable()`; `timestamp('created_at')->default('CURRENT_TIMESTAMP')`; `index(['tenant_uuid', 'created_at'], 'idx_events_tenant_created')`. `down()`/`getDescription()`.
- [ ] Register migration 003 in `SubscriptionsTestCase::setUp()`.
- [ ] Run `vendor/bin/phpunit --filter=MigrationsTest` -- expect **PASS** (including the multiple-NULLs case).
- [ ] **Commit (implementer):** `feat(schema): subscription_events with DB-enforced logical-key dedupe (multiple NULLs allowed)`.

---

## Phase 4 -- Resolution + checker (S2, S3, S11, S12)

### Task 4.1 -- SubscriptionRepository + OverrideRepository (reads)

**Files:**
- Create: `src/Repositories/SubscriptionRepository.php`
- Create: `src/Repositories/OverrideRepository.php`
- Create: `tests/Integration/Repositories/SubscriptionRepositoryTest.php`

Steps:
- [ ] Write failing test: seed a subscription via `seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active'])`; assert `SubscriptionRepository::findByTenant($ctx, 'tenantA')` returns an array with `plan_key => 'pro'`; `findByTenant($ctx, 'ghost')` returns `null`; seed an override row and assert `OverrideRepository::activeForTenant($ctx, 'tenantA')` returns the non-expired override and excludes a row whose `expires_at` is in the past.
- [ ] Run `vendor/bin/phpunit --filter=SubscriptionRepositoryTest` -- expect **FAIL**.
- [ ] Create `src/Repositories/SubscriptionRepository.php`:
  - `findByTenant(ApplicationContext $ctx, string $tenantUuid): ?array` -> `db($ctx)->table('subscriptions')->where('tenant_uuid', $tenantUuid)->first()` (return null if empty).
  - `findByPayviaSubscription(ApplicationContext $ctx, string $gateway, string $payviaSubscriptionId): ?array` -- matches on BOTH `payvia_gateway` and `payvia_subscription_id` (the provider-sub id is only unique within a gateway).
  - `latestUpdatedAt(array $row): ?string` helper returning `$row['updated_at'] ?? $row['created_at'] ?? null` (used by the cache key).
  - Write methods used later: `insert(ApplicationContext, array): void`, `updateByTenant(ApplicationContext, string $tenantUuid, array $changes): void` (sets `updated_at = now`).
- [ ] Create `src/Repositories/OverrideRepository.php`:
  - `activeForTenant(ApplicationContext $ctx, string $tenantUuid): array` -> rows where `tenant_uuid = ?` AND (`expires_at IS NULL` OR `expires_at > now`); decode each `value` JSON; return `['entitlement' => $decodedValue, ...]` keyed by entitlement plus a `maxUpdatedAt` accessor (or a second method `maxUpdatedAt(...)`). Keep value decoding via `json_decode(..., true)`.
  - `maxUpdatedAt(ApplicationContext $ctx, string $tenantUuid): ?string` -> latest `updated_at` among active overrides (for the cache key, S12).
- [ ] Run `vendor/bin/phpunit --filter=SubscriptionRepositoryTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(repos): SubscriptionRepository + OverrideRepository reads`.

### Task 4.2 -- EffectivePlanResolver (status gating: S2, S11)

**Files:**
- Create: `src/Resolution/EffectivePlanResolver.php`
- Create: `tests/Unit/Resolution/EffectivePlanResolverTest.php`

Steps:
- [ ] Write failing test covering the status table (pure function over a subscription row array + default plan + "now"):
  - no subscription (`null`) -> `default_plan`.
  - `active` -> the subscription's `plan_key`.
  - `trialing` -> the subscription's `plan_key` (S11, plan-as-trialed).
  - `past_due` with `grace_ends_at` in the **future** -> `plan_key` (dunning grace).
  - `past_due` with `grace_ends_at` in the **past** -> `default_plan` (downgrade).
  - `past_due` with `grace_ends_at` **null** -> `default_plan` (downgrade).
  - `incomplete` -> `default_plan` (never activated).
  - `canceled` -> `default_plan`.
- [ ] Run `vendor/bin/phpunit --filter=EffectivePlanResolverTest` -- expect **FAIL**.
- [ ] Create `src/Resolution/EffectivePlanResolver.php` with `resolve(?array $subscription, string $defaultPlan, \DateTimeImmutable $now): string` implementing exactly the spec's step 2 table. Parse `grace_ends_at` via `new \DateTimeImmutable($subscription['grace_ends_at'])` only when non-null/non-empty; compare against `$now`. Unknown statuses fall through to `default_plan`.
- [ ] Run `vendor/bin/phpunit --filter=EffectivePlanResolverTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(resolution): EffectivePlanResolver status gating (grace/incomplete/canceled -> default)`.

### Task 4.3 -- EntitlementResolver (map build + cache: S12)

**Files:**
- Create: `src/Resolution/EntitlementResolver.php`
- Create: `tests/Integration/Resolution/EntitlementResolverTest.php`

Steps:
- [ ] Write failing test using `SubscriptionsTestCase`. Construct the resolver with a `PlanCatalog` from a fixed config, the two repos, the `EffectivePlanResolver`, and a **null cache** (resolve uncached). Cases:
  - tenant on `pro` active -> resolved map equals the `pro` catalog entitlements.
  - tenant on `pro` but `canceled` -> resolved map equals the **default (`free`)** catalog entitlements (downgrade, S2).
  - tenant with no subscription -> resolved map equals default plan entitlements.
  - tenant with an active override `projects.limit => 999` -> resolved map's `projects.limit` is `999` (override wins per key); an **expired** override does NOT apply.
  - tenant on `free` with override adding a brand-new key -> key present in resolved map.
- [ ] Run `vendor/bin/phpunit --filter=EntitlementResolverTest` -- expect **FAIL**.
- [ ] Create `src/Resolution/EntitlementResolver.php`. Constructor: `(PlanCatalog $catalog, SubscriptionRepository $subs, OverrideRepository $overrides, EffectivePlanResolver $planResolver, ?CacheStore $cache, bool $cacheEnabled, int $cacheTtl)`.
  - `resolveMap(ApplicationContext $ctx, string $tenantUuid): array`:
    1. Load subscription row.
    2. `$planKey = $planResolver->resolve($subscription, $catalog->defaultPlan(), new \DateTimeImmutable('now'))`.
    3. `$map = $catalog->entitlementsFor($planKey)`.
    4. Merge active overrides: `foreach ($overrides->activeForTenant(...) as $key => $value) { $map[$key] = $value; }` (override value wins per key; uses array assignment so new keys are added).
    5. Return `$map`.
  - Caching: when `$cacheEnabled && $cache !== null`, wrap the resolution in `$cache->remember($cacheKey, fn() => ..., $ttl)`. Build `$cacheKey` from `'subscriptions.ent:' . $tenantUuid . ':' . $catalog->version() . ':' . ($sub['updated_at'] ?? $sub['created_at'] ?? '0') . ':' . ($overrides->maxUpdatedAt($ctx, $tenantUuid) ?? '0')` (S12 -- any change to subscription/override/catalog naturally invalidates). NOTE: load the subscription row (cheap query) to build the key, then `remember` the heavier override merge -- the row read is needed for the key anyway.
  - Add `forget(...)` is unnecessary (key-derived invalidation) -- do not add it (YAGNI).
- [ ] Run `vendor/bin/phpunit --filter=EntitlementResolverTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(resolution): EntitlementResolver (catalog + overrides + naturally-keyed cache)`.

### Task 4.4 -- DefaultEntitlementChecker (S3 semantics)

**Files:**
- Create: `src/DefaultEntitlementChecker.php`
- Create: `tests/Integration/DefaultEntitlementCheckerTest.php`

Steps:
- [ ] Write failing test (the S3 truth table is correctness-critical -- cover every branch). With `free` plan (`reports.export=false`, `projects.limit=3`) and `pro` (`reports.export=true`, `projects.limit=50`, `api.monthly=100000`, and add a key `support.priority => true` and an explicit-unlimited key `storage.gb => null` to the test config):
  - **Absent key denies (the typo case):** `allows($t, 'reports.exprot')` (typo) -> `false`; `limit($t, 'reports.exprot')` -> `0` (use `array_key_exists`, not `isset`).
  - `false` value: `allows($freeT, 'reports.export')` -> `false`; `limit` -> `0`.
  - `true` value: `allows($proT, 'reports.export')` -> `true`; `limit($proT, 'reports.export')` -> `null` (boolean-true is unlimited).
  - explicit `null` value: `allows($proT, 'storage.gb')` -> `true`; `limit($proT, 'storage.gb')` -> `null` (unlimited, configured explicitly -- distinct from absent).
  - int `n>0`: `allows($proT, 'projects.limit')` -> `true`; `limit($proT, 'projects.limit')` -> `50`.
  - int `0`: a plan with `projects.limit => 0` -> `allows` false, `limit` 0.
  - lapsed `pro` (canceled) tenant -> `allows($t, 'reports.export')` -> `false` (downgraded to free where it is false).
- [ ] Run `vendor/bin/phpunit --filter=DefaultEntitlementCheckerTest` -- expect **FAIL**.
- [ ] Create `src/DefaultEntitlementChecker.php` (namespace `Glueful\Extensions\Subscriptions`) implementing `Glueful\Entitlements\Contracts\EntitlementCheckerInterface` (the **framework-core** contract -- not defined in this package). Constructor: `(EntitlementResolver $resolver, ApplicationContext $context)`.
  - Private `resolve(string $tenantUuid): array` -> `$this->resolver->resolveMap($this->context, $tenantUuid)`.
  - `allows(...)`: `$map = $this->resolve($tenantUuid); if (!array_key_exists($entitlement, $map)) return false;` then map the value: `false`/`0`/`'0'` -> false; `true`/`null` -> true; numeric `> 0` -> true; numeric `<= 0` -> false; otherwise non-empty truthy -> true.
  - `limit(...)`: `$map = $this->resolve($tenantUuid); if (!array_key_exists($entitlement, $map)) return 0;` then: value `true` or `null` -> `null` (unlimited); value `false` -> `0`; numeric -> `(int)$value`.
  - Centralize the value->semantics mapping in two small private helpers `mapAllows(mixed): bool` and `mapLimit(mixed): ?int` (DRY) so `allows`/`limit` and any test stay consistent.
- [ ] Run `vendor/bin/phpunit --filter=DefaultEntitlementCheckerTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(checker): DefaultEntitlementChecker with array_key_exists deny-on-absent (S3)`.

---

## Phase 5 -- Middleware (S4)

### Task 5.1 -- CurrentTenant convenience (soft tenancy dep)

**Files:**
- Create: `src/Tenant/CurrentTenant.php`
- Create: `tests/Unit/Tenant/CurrentTenantTest.php`

Steps:
- [ ] Write failing test proving "works with NO tenancy":
  - When `Glueful\Extensions\Tenancy\Context\TenantContext` does NOT exist (or returns null context), `CurrentTenant::resolve($context)` returns `null` and does not fatal.
  - When a tenancy `TenantContext` IS present and holds a tenant, `resolve(...)` returns its uuid. (Simulate by setting request-state `'tenancy.tenant'` on the context with a tiny fake tenant object exposing a public `uuid`, since the real reader uses `TenantContext`/`requestState`.) Assert the returned uuid matches.
- [ ] Run `vendor/bin/phpunit --filter=CurrentTenantTest` -- expect **FAIL**.
- [ ] Create `src/Tenant/CurrentTenant.php` with `public static function resolve(ApplicationContext $context): ?string`:
  - Guard `if (!class_exists(\Glueful\Extensions\Tenancy\Context\TenantContext::class)) return null;` (soft dep).
  - Read the active tenant via tenancy's `TenantContext`: `(new \Glueful\Extensions\Tenancy\Context\TenantContext($context))->currentTenantUuid()` returns `?string`. Return it directly.
  - Wrap in `try/catch (\Throwable)` returning `null` so any tenancy-internal failure degrades to "no tenant" rather than fataling.
- [ ] Run `vendor/bin/phpunit --filter=CurrentTenantTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(tenant): CurrentTenant convenience reading tenancy TenantContext (class_exists-guarded)`.

> **Note for implementer:** `TenantContext::currentTenantUuid()` reads from `ApplicationContext::requestState` (verified in tenancy source). The middleware passes its injected `ApplicationContext`, which the `tenant` middleware has already populated. No dependency on the tenancy package at compile time -- only a runtime `class_exists` probe.

### Task 5.2 -- RequireEntitlement middleware + attribute (S4)

**Files:**
- Create: `src/Http/RequireEntitlement.php`
- Create: `tests/Integration/Http/RequireEntitlementTest.php`

Steps:
- [ ] Write failing test (fail-closed asymmetry is correctness-critical):
  - Tenant resolves and is on a plan that allows the entitlement -> `$next` is called, its response passes through.
  - Tenant resolves but the plan denies -> response is a `403` with an `entitlement` error code; `$next` NOT called.
  - **No tenant resolves + default config (`permissive_middleware=false`)** -> `403` (fail closed); `$next` NOT called.
  - **No tenant resolves + `permissive_middleware=true`** -> `$next` IS called (no-op allow).
  - (Drive the checker via a fake `EntitlementCheckerInterface` injected into the middleware; drive tenant presence by toggling what `CurrentTenant::resolve` returns -- inject a closure/strategy or set request-state so the real resolver returns null vs a uuid.)
- [ ] Run `vendor/bin/phpunit --filter=RequireEntitlementTest` -- expect **FAIL**.
- [ ] Create `src/Http/RequireEntitlement.php`. Two concerns in one file is fine, but prefer: a `#[\Attribute]` class `RequireEntitlement` carrying the entitlement string for attribute routing, AND the middleware. If the framework's attribute-routing requires the attribute and middleware be distinct, split into `src/Http/RequireEntitlementAttribute.php` (the `#[Attribute]`) and `src/Http/RequireEntitlement.php` (the `RouteMiddleware`). **Verify against `framework/src/Routing/Attributes/` how `#[Fields]`/route attributes attach middleware before choosing.** (See blocker note B1 below.)
  - Middleware implements `Glueful\Routing\RouteMiddleware`: `handle(Request $request, callable $next, mixed ...$params): mixed`. Constructor injects `EntitlementCheckerInterface $checker` and `ApplicationContext $context`.
  - First `$params` element is the required entitlement string. If absent -> treat as misconfiguration: return `Response::error('Entitlement gate misconfigured', 500)`.
  - `$tenantUuid = CurrentTenant::resolve($this->context);`
  - If `$tenantUuid === null`: if `config($context, 'subscriptions.permissive_middleware', false) === true` -> `return $next($request);`; else `return Response::error('Entitlement check failed: no tenant context', Response::HTTP_FORBIDDEN)` with error code `entitlement`.
  - Else: `if ($this->checker->allows($tenantUuid, $entitlement)) return $next($request); return Response::error('Entitlement required', Response::HTTP_FORBIDDEN)` with an `entitlement` error code (so clients can prompt upgrade). Use `Glueful\Http\Response` (verify the exact `Response::error($message, $status, $errorCode?)` signature against `framework/src/Http/Response.php`; if it has no error-code slot, set the code inside the payload array).
- [ ] Run `vendor/bin/phpunit --filter=RequireEntitlementTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(middleware): RequireEntitlement fail-closed 403 + permissive_middleware opt-in (S4)`.

### Task 5.3 -- EntitlementTierResolver (rate-limit bridge, the core-seam consumer)

> **Mapping RESOLVED for v1: tier-flag (S-rl).** The framework rate limiter resolves a tier *name* via `TierResolverInterface::resolve(Request): string`, then `TierManager` maps that tier to numbers. v1 uses the **tier-flag** mapping: entitlements pick the *bucket*, `TierManager` config owns the *numbers*. The resolver reads boolean `rate.tier.{tier}` entitlements for the configured tiers (highest-first) and returns the highest granted tier. This is a pure `TierResolverInterface` implementation -- the least-invasive integration, and core rate limiting stays untouched. The alternative **numeric-quota** mapping (per-tenant exact `limit('api.rate.{window}')` caps) is a **documented v1.1+ option** -- it needs the limit-resolution path to accept an override, so it is out of v1.

**Files:**
- Create: `src/RateLimiting/EntitlementTierResolver.php`
- Create: `tests/Integration/RateLimiting/EntitlementTierResolverTest.php`
- Modify: `config/subscriptions.php` (Task 1.3 stub) -- add `'rate_tiers' => ['enterprise', 'pro']` (ordered **highest-first**; the paid tiers that carry `rate.tier.{tier}` entitlement flags; lower tiers like `free`/`anonymous` are left to the default resolver).

Steps:
- [ ] Write failing tests:
  - **No tenant resolves** (tenancy absent / no context) -> `resolve($request)` delegates to the wrapped default `TierResolver` and returns its tier (NOT an entitlement tier). Proves the bridge is inert without tenancy.
  - **Tier-flag mapping** -> a tenant whose plan sets entitlement `rate.tier.pro => true` (and not `rate.tier.enterprise`), with `rate_tiers => ['enterprise', 'pro']`, makes `resolve()` return `'pro'`.
  - **No granted tier** -> a tenant with no `rate.tier.*` entitlement delegates to the wrapped default.
  - **Lookup failure degrades** -> if the entitlement read throws, the resolver falls back to the wrapped default rather than hard-failing rate limiting.
- [ ] Run `vendor/bin/phpunit --filter=EntitlementTierResolverTest` -- expect **FAIL**.
- [ ] Create `src/RateLimiting/EntitlementTierResolver.php` implementing `Glueful\Api\RateLimiting\Contracts\TierResolverInterface`. Constructor: `(\Glueful\Entitlements\Contracts\EntitlementCheckerInterface $checker, ApplicationContext $context, \Glueful\Api\RateLimiting\TierResolver $inner)` -- `$inner` is the framework default resolver (autowired; depends only on `TierManager`, no cycle).
  - `resolve(\Symfony\Component\HttpFoundation\Request $request): string`, wrapped entirely in `try { ... } catch (\Throwable) { return $this->inner->resolve($request); }` (an entitlement read must never hard-fail rate limiting):
    1. `$tenantUuid = CurrentTenant::resolve($this->context);`
    2. If `$tenantUuid === null` -> `return $this->inner->resolve($request);` (no tenant: defer to the default user/role-based tiering).
    3. Tier-flag mapping: `foreach ((array) config($this->context, 'subscriptions.rate_tiers', []) as $tier) { if ($this->checker->allows($tenantUuid, "rate.tier.{$tier}")) { return (string) $tier; } }` then `return $this->inner->resolve($request);` (no granted tier -> default tiering).
- [ ] Binding over the default is done in `services()` (Task 7.1, already added).
- [ ] Run `vendor/bin/phpunit --filter=EntitlementTierResolverTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(ratelimit): EntitlementTierResolver tier-flag bridge over the default resolver (S-rl)`.

> **Note:** This resolver consumes TWO framework seams -- the core `EntitlementCheckerInterface` and `TierResolverInterface` -- and depends on neither tenancy nor payvia at compile time (tenant via the `class_exists`-guarded `CurrentTenant`). It is the concrete cross-domain wiring that, per the seam rule, lives in the extension and never in core.

---

## Phase 6 -- Lifecycle + reconcile (S6, S7, S8, S10)

### Task 6.1 -- SubscriptionEventRepository (DB-enforced dedupe)

**Files:**
- Create: `src/Repositories/SubscriptionEventRepository.php`
- Create: `tests/Integration/Repositories/SubscriptionEventRepositoryTest.php`

Steps:
- [ ] Write failing test:
  - `append($ctx, [...])` with a `payvia_gateway` + `payvia_logical_event_key` inserts one row and returns `true`.
  - A second `append(...)` with the **same** `(payvia_gateway, payvia_logical_event_key)` returns `false` (caught unique violation -> no-op) and leaves exactly one row (the duplicate-event no-op, S6).
  - The same `payvia_logical_event_key` under a **different** `payvia_gateway` succeeds (per-gateway dedupe).
  - Two `append(...)` calls with `payvia_logical_event_key => null` (manual/reconcile) both succeed and produce two rows.
- [ ] Run `vendor/bin/phpunit --filter=SubscriptionEventRepositoryTest` -- expect **FAIL**.
- [ ] Create `src/Repositories/SubscriptionEventRepository.php`:
  - `insertOrThrow(ApplicationContext $ctx, array $event): void`: fill `uuid => Utils::generateNanoID(12)`; json-encode `data` if array; `db($ctx)->table('subscription_events')->insert($row)` -- does NOT catch, so the `(payvia_gateway, payvia_logical_event_key)` unique violation **propagates**. This lets a caller use the insert as a transactional claim (the listener wraps it in a transaction so a duplicate rolls back the projection).
  - `append(ApplicationContext $ctx, array $event): bool`: convenience wrapper for paths with no projection to gate (manual/reconcile) -- `try { $this->insertOrThrow($ctx, $event); return true; } catch (\Throwable $e) { if ($this->isUniqueViolation($e)) return false; throw $e; }`.
  - `existsByLogicalKey(ApplicationContext $ctx, string $gateway, string $key): bool` -> gateway-scoped read-side pre-check (cheap early-out; the transactional `insertOrThrow` claim is the real gate).
  - `public function isUniqueViolation(\Throwable $e): bool` -- matches SQLSTATE `23000`/`23505` or a "unique"/"UNIQUE constraint failed" message substring (cross-driver). **Public** so the listener can branch on it.
- [ ] Run `vendor/bin/phpunit --filter=SubscriptionEventRepositoryTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(events): SubscriptionEventRepository append with unique-violation no-op (S6)`.

### Task 6.2 -- SubscriptionService lifecycle (works with NO payvia)

**Files:**
- Create: `src/SubscriptionService.php`
- Create: `tests/Integration/SubscriptionServiceTest.php`

Steps:
- [ ] Write failing test proving "works with NO payvia" (free/trial/comp):
  - `start('tenantA', 'free')` with no options creates a `subscriptions` row: `plan_key=free`, `status=active`, all `payvia_*` NULL; appends a `created` event (source `manual`). (The service holds `ApplicationContext` in its constructor -- methods take no `$ctx`.)
  - `start('tenantB', 'pro', ['status' => 'trialing', 'trial_ends_at' => '2026-07-01 00:00:00'])` -> `status=trialing`, `payvia_*` NULL.
  - `current('tenantA')` returns the row.
  - `changePlan('tenantA', 'pro')` updates `plan_key=pro`, appends a `plan_changed` event with `from`/`to` data.
  - `cancel('tenantA', atPeriodEnd: false)` sets `status=canceled`, `canceled_at` set, appends a `canceled` event.
  - All without any payvia class present.
- [ ] Run `vendor/bin/phpunit --filter=SubscriptionServiceTest` -- expect **FAIL**.
- [ ] Create `src/SubscriptionService.php`. Constructor: `(SubscriptionRepository $subs, SubscriptionEventRepository $events, PlanCatalog $catalog, ApplicationContext $context)`.
  - `current(string $tenantUuid): ?array`.
  - `start(string $tenantUuid, string $planKey, array $opts = []): array`: build row (`uuid`, `tenant_uuid`, `plan_key`, `status` from `$opts['status'] ?? 'active'`, optional `trial_ends_at`/`current_period_end`, all `payvia_*` from `$opts` or null, `metadata`); insert; append `created` event (source `manual`, `to_status` = status); return the row.
  - `changePlan(string $tenantUuid, string $planKey): array`: load current, `updateByTenant(... ['plan_key' => $planKey])`, append `plan_changed` event with `data => ['from_plan' => $old, 'to_plan' => $planKey]`.
  - `cancel(string $tenantUuid, bool $atPeriodEnd = true): array`: if `$atPeriodEnd` set `metadata`/flag and keep status until period end (set `canceled_at` null, mark `cancel_at_period_end` in metadata); else set `status=canceled` + `canceled_at=now`. Append `canceled` event with `from_status`/`to_status`.
  - `reconcile(string $tenantUuid): array` -- STUB for now returning `current()`; filled in Task 6.4. Keep the method present so the public surface is stable.
  - All status transitions append via `SubscriptionEventRepository::append` with `source` set appropriately (`manual` here).
- [ ] Run `vendor/bin/phpunit --filter=SubscriptionServiceTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(service): SubscriptionService lifecycle (start/current/changePlan/cancel) -- payvia-free`.

### Task 6.3 -- PaymentProviderEventListener (conditional, projection + dedupe)

**Files:**
- Create: `src/Listeners/PaymentProviderEventListener.php`
- Create: `tests/Support/FakeProviderEvent.php`
- Create: `tests/Support/FakePaymentProviderEvent.php`
- Create: `tests/Integration/Listeners/PaymentProviderEventListenerTest.php`

Steps:
- [ ] Create in-suite fakes so tests do NOT need payvia installed:
  - `tests/Support/FakeProviderEvent.php` -- implements the *shape* of `Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface` (methods `gateway()`, `type()`, `providerEventId()`, `deliveryKey()`, `logicalEventKey()`, `occurredAt()`, `normalized()`, `raw()`). It does NOT `implements` the payvia interface (absent at test time) -- it is duck-typed; the listener reads via these methods.
  - `tests/Support/FakePaymentProviderEvent.php` -- a `Glueful\Events\Contracts\BaseEvent` subclass with a public readonly `$event` (the `FakeProviderEvent`), mirroring payvia's `PaymentProviderEvent` shape, so the listener can read `$payviaEvent->event`.
- [ ] Write failing test:
  - Seed a subscription with `payvia_gateway => 'paystack'`, `payvia_subscription_id => 'sub_X'`, `tenant_uuid => 'tenantA'`, `status => 'trialing'`.
  - Dispatch a fake `subscription.past_due` event (`gateway()` = `'paystack'`, logicalEventKey `k1`, normalized carrying `gateway_subscription_id => 'sub_X'`) through the listener -> subscription `status` becomes `past_due`, `grace_ends_at` ~= now + `grace_days`; one `subscription_events` row (source `payvia_event`, gateway `paystack`, key `k1`).
  - Re-dispatch the **same** event (`paystack`/`k1`) -> **no-op**: status unchanged, **`grace_ends_at` unchanged (NOT extended)** -- the claim-first transaction means the duplicate never re-projects; still exactly one event row (duplicate event no-op, S6). *(This is the concurrency-idempotency guarantee: a duplicate/retried `past_due` cannot extend the grace window.)*
  - Dispatch `payment.succeeded` (key `k2`) for a `past_due` sub -> settles to `active`, `grace_ends_at` cleared, new event row.
  - Dispatch an event whose `gateway_subscription_id` maps to NO subscription -> listener no-ops gracefully (no throw, no rows).
- [ ] Run `vendor/bin/phpunit --filter=PaymentProviderEventListenerTest` -- expect **FAIL**.
- [ ] Create `src/Listeners/PaymentProviderEventListener.php` (namespace `Glueful\Extensions\Subscriptions\Listeners`). Constructor: `(SubscriptionRepository $subs, SubscriptionEventRepository $events, PlanCatalog $catalog, ApplicationContext $context)`.
  - Public `__invoke(object $payviaEvent): void` (PSR-14 callable listener). Read `$inner = $payviaEvent->event;` then `$type = $inner->type();`, `$logicalKey = $inner->logicalEventKey();`, `$normalized = $inner->normalized();`.
  - Read `$gateway = $inner->gateway();`.
  - Dedupe pre-check (cheap early-out ONLY, not the safety gate): `if ($logicalKey !== '' && $this->events->existsByLogicalKey($this->context, $gateway, $logicalKey)) return;`. This is best-effort -- it does NOT make the handler concurrency-safe; the transactional claim below is the real gate.
  - Map provider sub -> tenant: `$gwSubId = $normalized['gateway_subscription_id'] ?? null; $sub = ($gateway !== '' && $gwSubId) ? $this->subs->findByPayviaSubscription($this->context, $gateway, $gwSubId) : null;`. If `$sub === null` and the type is `subscription.created`, attempt recovery from `$normalized['metadata']['tenant_uuid']` (link by writing BOTH `payvia_gateway` and `payvia_subscription_id`). If still no tenant -> `return` (graceful no-op).
  - `switch ($type)` per the spec mapping (`subscription.created/updated/past_due/canceled`, `payment.succeeded`, `invoice.paid`). Compute `$changes` (status/period/grace/canceled_at) and `$to`/`$from` statuses **from the loaded sub** (before applying). For `subscription.past_due`: set `grace_ends_at = now + grace_days`. For settle paths (`payment.succeeded`/`invoice.paid`): if current status is `trialing`/`past_due` set `active` and clear `grace_ends_at`.
  - **Apply atomically -- claim FIRST, then project (concurrency-safe idempotency).** Insert the event row (the claim) and project the subscription in ONE transaction; the `(payvia_gateway, payvia_logical_event_key)` unique constraint is the atomic gate, so a duplicate/concurrent handler that loses the claim rolls back and **never re-projects**:
    ```php
    try {
        db($this->context)->transaction(function () use ($sub, $changes, $gateway, $logicalKey, $from, $to, $type, $normalized) {
            // (1) CLAIM -- throws on (payvia_gateway, payvia_logical_event_key) duplicate
            $this->events->insertOrThrow($this->context, [
                'tenant_uuid' => $sub['tenant_uuid'], 'type' => $type,
                'from_status' => $from, 'to_status' => $to, 'source' => 'payvia_event',
                'payvia_gateway' => $gateway, 'payvia_logical_event_key' => $logicalKey, 'data' => $normalized,
            ]);
            // (2) PROJECT -- only the claim winner reaches here
            $this->subs->updateByTenant($this->context, $sub['tenant_uuid'], $changes);
        });
    } catch (\Throwable $e) {
        if ($this->events->isUniqueViolation($e)) {
            return; // duplicate/concurrent handler already owns this logical event -> no projection
        }
        throw $e;
    }
    ```
    This is what makes `subscription.past_due` safe: `grace_ends_at = now + grace_days` is applied **exactly once** (only the claim winner projects), so a duplicate or retried delivery can never extend the grace window. Applying the projection *before* winning the insert (the previous ordering) would let two handlers both extend grace -- the bug this fixes.
- [ ] Run `vendor/bin/phpunit --filter=PaymentProviderEventListenerTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(listener): PaymentProviderEventListener projection + logical-key dedupe (S6)`.

### Task 6.4 -- SubscriptionService::reconcile (soft payvia)

**Files:**
- Modify: `src/SubscriptionService.php`
- Create: `tests/Integration/SubscriptionReconcileTest.php`

Steps:
- [ ] Write failing test:
  - A subscription with `payvia_subscription_id` NULL (free/comp) -> `reconcile($ctx, $tenant)` is a no-op returning the unchanged row (nothing to pull); no throw even with NO payvia installed.
  - A subscription with a `payvia_subscription_id` set, given an injected fake "payvia reader" that returns authoritative provider state `{ status: 'past_due', current_period_end: ... }` -> reconcile re-derives status, applies drift, appends a `reconciled` event (source `reconcile`, `payvia_logical_event_key` NULL).
- [ ] Run `vendor/bin/phpunit --filter=SubscriptionReconcileTest` -- expect **FAIL**.
- [ ] Implement `reconcile(string $tenantUuid): array` in `SubscriptionService`:
  - Load current; if no `payvia_subscription_id` -> return unchanged (no-op).
  - Resolve the Payvia reconcile seam *optionally*: a private `pullProviderState(string $gateway, string $gwSubId): ?array` that returns null when Payvia is absent. Guard with `if (!class_exists(\Glueful\Extensions\Payvia\Services\GatewaySubscriptionService::class)) return null;` then call Payvia's `reconcile($gateway, $gwSubId)` via the container (`app($this->context, ...)`) -- **verify the exact Payvia reconcile service/method name against the payvia v-next spec / payvia src when present** (the spec names a service method `reconcile(string $gateway, string $gatewaySubscriptionId)`; the concrete class is created in the Payvia implementation, so resolve by interface/known class name and degrade to no-op if absent -- see blocker B2). To keep this testable without payvia, accept an optional injected `?callable $providerStatePuller = null` constructor seam the test supplies; production wiring passes the real puller in Phase 7.
  - If provider state returned: map its normalized `status`/period onto the subscription via `updateByTenant`, append a `reconciled` event (source `reconcile`, logical key NULL).
  - Return the (re-read) current row.
- [ ] Run `vendor/bin/phpunit --filter=SubscriptionReconcileTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(service): reconcile pulls provider state via soft payvia seam (no-op without payvia)`.

### Task 6.5 -- Console commands: reconcile + ops (S8, S10)

**Files:**
- Create: `src/Console/ReconcileCommand.php`
- Create: `src/Console/ShowSubscriptionCommand.php`
- Create: `src/Console/SetPlanCommand.php`
- Create: `tests/Integration/Console/ConsoleCommandsTest.php`

Steps:
- [ ] Write failing test (use `Symfony\Component\Console\Tester\CommandTester` with a command wired to the test harness context, mirroring tenancy's `ConsoleCommandsTest`):
  - `subscriptions:show --tenant=tenantA` prints the subscription row fields after one is seeded; prints "No subscription" when none.
  - `subscriptions:set-plan --tenant=tenantA --plan=pro` changes the plan (asserts `plan_key=pro` after).
  - `subscriptions:reconcile --tenant=tenantA` runs without error on a non-payvia subscription (no-op) and reports success.
- [ ] Run `vendor/bin/phpunit --filter=ConsoleCommandsTest` -- expect **FAIL**.
- [ ] Create the three commands extending `BaseCommand`, each with `#[AsCommand(...)]`:
  - `ReconcileCommand` -- name `subscriptions:reconcile`, optional `--tenant=` option. With `--tenant`, reconcile that one; without, iterate all subscriptions that have a `payvia_subscription_id` and reconcile each. Resolve `SubscriptionService` via `app($this->getContext(), SubscriptionService::class)`. Print a summary line.
  - `ShowSubscriptionCommand` -- name `subscriptions:show`, required `--tenant=`. Print row as a key/value `table`.
  - `SetPlanCommand` -- name `subscriptions:set-plan`, required `--tenant=` and `--plan=`. If no subscription exists, `start(...)`; else `changePlan(...)`. Validate the plan exists in the catalog (`PlanCatalog::entitlementsFor` non-empty) else `error` + non-zero exit.
- [ ] Run `vendor/bin/phpunit --filter=ConsoleCommandsTest` -- expect **PASS**.
- [ ] **Commit (implementer):** `feat(cli): subscriptions:reconcile/show/set-plan ops commands`.

---

## Phase 7 -- Wiring & docs

### Task 7.1 -- Provider registrations (services + alias + commands + conditional listener)

**Files:**
- Modify: `src/SubscriptionsServiceProvider.php`
- Create: `tests/Integration/ServiceProviderWiringTest.php`

Steps:
- [ ] Write failing test:
  - `SubscriptionsServiceProvider::services()` binds `\Glueful\Entitlements\Contracts\EntitlementCheckerInterface` to `DefaultEntitlementChecker` (shared) and `\Glueful\Api\RateLimiting\Contracts\TierResolverInterface` to `EntitlementTierResolver` (shared), plus entries for `SubscriptionService`, the repositories, `EntitlementResolver`, `PlanCatalog`, and `RequireEntitlement` with alias `require_entitlement`.
  - `middlewareAliases()` (docs/tests helper, like tenancy) maps `require_entitlement => RequireEntitlement::class`.
  - A test that boot() registers the Payvia listener ONLY when `Glueful\Extensions\Payvia\Events\PaymentProviderEvent` exists -- assert that when the class is absent, boot() does not throw and registers no listener (probe via a spy `EventService` or assert `class_exists` guard path).
- [ ] Run `vendor/bin/phpunit --filter=ServiceProviderWiringTest` -- expect **FAIL**.
- [ ] Fill `services()`:
  - `\Glueful\Entitlements\Contracts\EntitlementCheckerInterface::class => ['class' => DefaultEntitlementChecker::class, 'shared' => true, 'autowire' => true]`. This **overrides** core's `NullEntitlementChecker` default (last-wins) -- which only works because of the framework container-precedence fix; without it, the core default would win and entitlements would always allow-all.
  - `\Glueful\Api\RateLimiting\Contracts\TierResolverInterface::class => ['class' => EntitlementTierResolver::class, 'shared' => true, 'autowire' => true]` -- binds the rate-limit bridge over the framework's default `TierResolver` (same last-wins override). The resolver wraps/delegates to the default when no tenant resolves (Task 5.3).
  - `PlanCatalog::class` via a `FactoryDefinition` building from `PlanCatalog::fromContext($c->get(ApplicationContext::class))` (config-driven, like tenancy's ResolverChain factory).
  - `SubscriptionRepository`, `OverrideRepository`, `SubscriptionEventRepository`, `EntitlementResolver`, `SubscriptionService` -- shared + autowire. For `EntitlementResolver`, supply `?CacheStore` + `cacheEnabled`/`cacheTtl` via a `FactoryDefinition` reading `config('subscriptions.cache')` and `$c->has(CacheStore::class) ? $c->get(CacheStore::class) : null` (optional cache).
  - `RequireEntitlement::class => ['class' => RequireEntitlement::class, 'shared' => true, 'autowire' => true, 'alias' => ['require_entitlement']]` (alias declared in `services()` so the router resolves it -- mirrors tenancy's `tenant` alias rationale).
  - Add `middlewareAliases(): array` static helper returning `['require_entitlement' => RequireEntitlement::class]`.
- [ ] In `boot()`:
  - `registerMeta` via `ExtensionManager` (wrapped in try/catch like payvia).
  - `discoverCommands('Glueful\\Extensions\\Subscriptions\\Console', __DIR__ . '/Console')`.
  - **Conditional listener (S7):** `if (class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class)) { app($context, EventService::class)->addListener(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class, '@' . PaymentProviderEventListener::class); }` -- register lazily via the container `@serviceId` form (EventService supports it) so the listener is constructed on dispatch. **Verify the exact FQCN of Payvia's event class against the payvia v-next spec/src** (`PaymentProviderEvent` -- the spec shows namespace `Glueful\Extensions\Payvia` for the contract; confirm the BaseEvent's namespace, likely `Glueful\Extensions\Payvia\Events`; see blocker B2). Also ensure `PaymentProviderEventListener` is in `services()` so the `@serviceId` lazy listener resolves.
- [ ] Run `vendor/bin/phpunit --filter=ServiceProviderWiringTest` -- expect **PASS**.
- [ ] Run the full suite `vendor/bin/phpunit` -- expect **all green**.
- [ ] **Commit (implementer):** `feat(provider): bind checker, middleware alias, commands, conditional payvia listener (S7)`.

### Task 7.2 -- README + CHANGELOG + USAGE

**Files:**
- Create/Modify: `README.md`
- Create: `CHANGELOG.md`
- Create: `docs/USAGE.md`

Steps:
- [ ] Write `README.md`: what it is (tenant subscriptions + entitlements), install (`composer require glueful/subscriptions`), the decoupling invariant (works with no payvia / no tenancy), quick `EntitlementCheckerInterface` usage, the `RequireEntitlement` middleware + `#[RequireEntitlement('reports.export')]`, the catalog config shape, and a "Consumes Payvia" section (priced plans, normalized `PaymentProviderEvent`, `gateway_subscriptions` reconcile) mirroring the spec's "What it consumes from Payvia".
- [ ] Write `CHANGELOG.md` with a `## 1.0.0 -- <date>` entry listing: contract + null/default checker, 3 tables at DEPENDENT, config catalog + overrides, status-gated resolution with cache, `RequireEntitlement` fail-closed middleware + `permissive_middleware`, `SubscriptionService` lifecycle, conditional Payvia listener with logical-key dedupe, `subscriptions:reconcile/show/set-plan` CLI. Note soft deps and the no-payvia/no-tenancy guarantees.
- [ ] Write `docs/USAGE.md`: entitlement checks in app code (`app($ctx, EntitlementCheckerInterface::class)->allows(...)`), middleware on routes, S3 semantics table (absent denies, true/null unlimited, int n), lifecycle via `SubscriptionService`, reconcile CLI + the opt-in scheduler hook (S10), and the soft-dep behavior matrix.
- [ ] **Verify:** spell-check the ASCII-only constraint (no smart quotes / em-dashes).
- [ ] **Commit (implementer):** `docs: README, CHANGELOG 1.0.0, usage guide (consumes-Payvia section)`.

### Task 7.3 -- Full CI pass + final review

**Files:** none (verification only)

Steps:
- [ ] Run `composer test` -- expect all suites green.
- [ ] Run `vendor/bin/phpcs --standard=PSR12 src` -- expect no violations (fix with `phpcbf` if needed).
- [ ] Run `vendor/bin/phpstan analyse src` -- expect clean (or document accepted baseline).
- [ ] Manually confirm: (a) no `src/` file references a payvia/tenancy class without a `class_exists` guard at the call site; (b) this package ships **no** `Glueful\Entitlements\` classes (no `src/Entitlements/`, single PSR-4 root) -- the contract is consumed from framework core; (c) `services()` binds `DefaultEntitlementChecker` over the core `EntitlementCheckerInterface` and `EntitlementTierResolver` over the default `TierResolver` (both rely on the framework container-precedence fix to win).
- [ ] **Commit (implementer):** `chore: green CI (phpunit + phpcs + phpstan)`.

---

## Blockers / spec ambiguities flagged (verify before/at the noted task; do not invent)

- **B1 (Task 5.2) -- attribute-routing + middleware attachment.** The spec shows `#[RequireEntitlement('reports.export')]` as an attribute equivalent to `->middleware(['require_entitlement:reports.export'])`. The framework's attribute-to-middleware wiring (how a route attribute auto-attaches a middleware alias with a param) must be verified against `framework/src/Routing/Attributes/` (compare `#[Fields]` / `#[RequireScope]` from CLAUDE.md, which "auto-attaches" middleware). If the framework offers no generic attribute->middleware bridge, ship the `require_entitlement:<entitlement>` middleware-string form as the supported API and provide the `#[RequireEntitlement]` attribute only if a sanctioned hook exists; otherwise document the middleware-string form and defer the attribute. This does not block the middleware itself.
- **B2 (Tasks 6.3, 6.4, 7.1) -- exact Payvia FQCNs are not yet implemented.** Payvia v-next is a *locked spec*, not yet code. The consumed symbols -- the `PaymentProviderEvent` BaseEvent class (the spec puts the contract in `Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface`; the BaseEvent's namespace is shown as `Glueful\Extensions\Payvia` in one sketch but is conventionally `...\Events`), and the `reconcile(string $gateway, string $gatewaySubscriptionId)` service method (concrete class name TBD) -- must be re-confirmed against payvia's source once payvia v-next is implemented. The plan isolates every such reference behind `class_exists` guards and (for reconcile) an injectable puller seam, so Subscriptions builds, tests, and ships GREEN with payvia absent; only the live integration wiring (the `addListener` FQCN and the reconcile service resolution) needs a one-line confirmation when payvia lands. Treat mismatches as a wiring fix, not a redesign.
- **B3 (Task 4.3 cache) -- `CacheStore` optionality.** `Glueful\Cache\CacheStore` is the verified cache seam (`get`/`set`/`remember`). It may be unbound in a zero-infra install; the resolver must accept `?CacheStore` and run uncached when null (spec S12 explicitly allows this). The provider factory resolves it via `$c->has(CacheStore::class) ? $c->get(...) : null`.

---

## Verified framework APIs (used by this plan)

- `Glueful\Extensions\ServiceProvider` -- `services()` (array DSL / `FactoryDefinition`), `register()`, `boot()`, `mergeConfig(string, array)`, `loadMigrationsFrom(string, int, string)`, `discoverCommands(string, string)`. (verified in `framework/src/Extensions/ServiceProvider.php`)
- `Glueful\Database\Migrations\MigrationPriority::DEPENDENT = 100`; `MigrationInterface` (`up`/`down`/`getDescription`). (verified)
- `TableBuilder` -- `->string($n,$len)`, `->json($n)`, `->timestamp($n)`, `->bigInteger($n)->primary()->autoIncrement()`, `->nullable()`, `->default(...)`, `->unique(array|string, ?name)`, `->index(array|string, ?name)`. Single-column `->unique()` on a nullable column permits multiple NULLs. (verified)
- `Glueful\Routing\RouteMiddleware::handle(Request, callable $next, mixed ...$params): mixed`. (verified)
- `Glueful\Events\Contracts\BaseEvent` (abstract; `parent::__construct()`); `Glueful\Events\EventService::dispatch(object)`, `addListener(string $eventClass, callable|string $listener, int $priority=0)` (supports `'@serviceId'` lazy listeners). (verified)
- `Glueful\Cache\CacheStore` -- `get`, `set`, `remember(string, callable, ?int)`. (verified)
- Helpers: `db($context): Connection`, `config($context, $key, $default)`, `app($context, $abstract)`, `container($context)`. (verified in `framework/src/helpers.php`)
- `Glueful\Helpers\Utils::generateNanoID(?int)` for 12-char uuids. (verified)
- `Glueful\Console\BaseCommand` -- `getContext()`, `info()`, `error()`, `table()`; `#[AsCommand]`. (verified)
- Tenancy soft-dep readers: `Glueful\Extensions\Tenancy\Context\TenantContext($context)->currentTenantUuid(): ?string` (reads requestState). Probed via `class_exists` only. (verified in tenancy src)
- **Core entitlement seam (consumed):** `Glueful\Entitlements\Contracts\EntitlementCheckerInterface` (`allows`/`limit`) + `Glueful\Entitlements\NullEntitlementChecker` default -- shipped by the framework entitlement-seam release, overridden here. (framework plan)
- **Rate-limit seam (consumed for the bridge):** `Glueful\Api\RateLimiting\Contracts\TierResolverInterface::resolve(Request): string`; default impl `Glueful\Api\RateLimiting\TierResolver` (ctor `(TierManager)`), overridden here via the container-precedence fix. (verified in `framework/src/Api/RateLimiting/`)

## Task count

22 tasks across 7 phases (contract + null moved to framework core; rate-limit bridge added):
- Phase 1 (skeleton): 4 -- 1.1, 1.2, 1.3, 1.4
- Phase 2 (plan catalog): 1 -- 2.1 (contract + Null now in framework core, not here)
- Phase 3 (schema): 3 -- 3.1, 3.2, 3.3
- Phase 4 (resolution + checker): 4 -- 4.1, 4.2, 4.3, 4.4
- Phase 5 (middleware + rate-limit bridge): 3 -- 5.1, 5.2, 5.3
- Phase 6 (lifecycle + reconcile): 5 -- 6.1, 6.2, 6.3, 6.4, 6.5
- Phase 7 (wiring + docs): 3 -- 7.1, 7.2, 7.3
