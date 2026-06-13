# Subscriptions Bring-Your-Own-Provider Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `glueful/subscriptions` provider-agnostic — projection logic owned by a generic projector consuming a `ProviderSubscriptionEvent` DTO, payvia reduced to a thin optional bridge/puller, and all subscriptions-owned `payvia_*` vocabulary renamed to `provider_*`.

**Architecture:** A `SubscriptionEventProjector` (behind `SubscriptionEventProjectorInterface`) owns all idempotency/relink/status rules and writes `provider_*` columns. A thin `PayviaSubscriptionEventBridge` adapts payvia's event shape into the DTO and is registered only when payvia exists. Reconcile pulls authoritative state through a `ProviderStatePullerInterface` with a payvia-backed default. Third-party providers register their own bridge listener / puller; no config registry, no provider-owned interface, no BC shims.

**Tech Stack:** PHP 8.3, PHPUnit 10.5, Glueful framework (`composer test` / `composer phpcs` / `composer analyze`). Reference spec: `docs/superpowers/specs/2026-06-13-subscriptions-bring-your-own-provider-design.md`.

**Working rules (all tasks):**
- Repo: `/Users/michaeltawiahsowah/Sites/glueful/extensions/subscriptions`, branch `dev`, commit directly to dev.
- Gates before every commit: `composer test` green; `composer phpcs` + `composer analyze` clean before the final commit of each task at minimum. Baseline is **200 tests**. Never commit on red.
- NO `Co-Authored-By`/`Generated-with` trailers. Conventional commit messages.
- CHANGELOG bullets go under `## Unreleased` (use `### Added`/`### Changed`/`### Fixed` matching existing heading style).
- The PHPStan "upgrade to 2.x" banner is a known benign nag, not an error.

**Test-harness facts (read before writing any test).** `tests/Support/SubscriptionsTestCase.php` exposes exactly these helpers — **use them; do not invent others**:
- `seedSubscription(array $overrides = []): array` — positional array (defaults `tenant_uuid='tenantA'`, `plan_key='free'`, `status='active'`); pass `provider_gateway`/`provider_subscription_id`/etc. as override keys.
- `appContext(): ApplicationContext`, `connection(): Connection`, `bind(string $id, mixed $service): void`, `setConfig(string $key, mixed $value): void`.

There is **no** `subscriptionRow()`, `eventCount()`, `makeService()`, or `$this->container`. Read state and construct subjects directly, mirroring `SubscriptionReconcileTest`:
```php
// read a subscription row
$row = $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $t)->first();
// read events for a tenant
$events = $this->connection()->table('subscription_events')->where('tenant_uuid', '=', $t)->get();
```
Each test class defines its own tiny private builder for the subject under test (see snippets below).

---

## File Structure

**New files:**
- `src/Projection/ProviderSubscriptionEvent.php` — DTO (generic input boundary).
- `src/Contracts/SubscriptionEventProjectorInterface.php` — projector contract.
- `src/Projection/SubscriptionEventProjector.php` — owns all projection/idempotency/relink/status rules (logic moved from the listener).
- `src/Bridge/PayviaSubscriptionEventBridge.php` — thin payvia adapter (replaces the listener).
- `src/Contracts/ProviderStatePullerInterface.php` — reconcile pull contract.
- `src/Bridge/PayviaProviderStatePuller.php` — payvia-backed default puller.
- `tests/Integration/Projection/SubscriptionEventProjectorTest.php` — ported listener tests.
- `tests/Integration/Bridge/PayviaSubscriptionEventBridgeTest.php` — adapter mapping test.
- `tests/Integration/Byop/CustomProviderExampleTest.php` — no-payvia worked example.
- `tests/Support/CallablePuller.php` — wraps a closure as a `ProviderStatePullerInterface` so existing closure-style reconcile tests stay unchanged.
- `docs/BRING_YOUR_OWN_PROVIDER.md` — BYOP guide.

**Deleted:**
- `src/Listeners/PaymentProviderEventListener.php` and `tests/Integration/Listeners/PaymentProviderEventListenerTest.php` (logic + cases move to projector/bridge tests).

**Renamed-in-place (payvia_* → provider_*):** all migrations, repositories, `SubscriptionService`, `PlanCatalog`, `PlanPayloadValidator`, `SubscriptionPlanRepository`, `PlanController`/`routes.php` docblocks, console commands, `config/subscriptions.php`, and all tests/support fakes.

---

## Task 1: The `provider_*` rename (one logical commit)

This is one logical rename committed together. Intermediate checkpoints use PHPStan and grep; the full test suite is expected to be red until tests are renamed in Step 7. DB columns are renamed directly in the base migrations (no new migration, no shim).

**Files (modify):** migrations `001`,`003`,`004`; `src/Repositories/SubscriptionRepository.php`, `SubscriptionEventRepository.php`, `SubscriptionPlanRepository.php`; `src/SubscriptionService.php`; `src/Catalog/PlanCatalog.php`; `src/Plans/PlanPayloadValidator.php`; `src/Listeners/PaymentProviderEventListener.php`; `routes.php`; `src/Console/Plans/CreatePlanCommand.php`, `UpdatePlanCommand.php`, `ListPlansCommand.php`; `src/Console/ReconcileCommand.php`; `config/subscriptions.php`; and every test under `tests/` plus `tests/Support/` fakes that reference these identifiers.

### Identifier mapping (apply everywhere)

| Old | New |
|---|---|
| `payvia_gateway` | `provider_gateway` |
| `payvia_customer_id` | `provider_customer_id` |
| `payvia_subscription_id` | `provider_subscription_id` |
| `payvia_logical_event_key` | `provider_logical_event_key` |
| `payvia_priced_plan_uuid` (col/option/payload/config) | `provider_price_id` |
| `payvia_priced_plan` (config key) | `provider_price_id` |
| `'payvia_event'` (`source` value) | `'provider_event'` |
| index `uniq_subscriptions_payvia_sub` | `uniq_subscriptions_provider_sub` |
| `findByPayviaSubscription` | `findByProviderSubscription` |
| `allWithPayvia` | `allWithProvider` |
| `pricedPlanUuid` (PlanCatalog) | `providerPriceId` |
| `validatePayviaPricedPlanUuid` | `validateProviderPriceId` |
| CLI option `payvia-priced-plan` | `provider-price-id` |
| `ListPlansCommand` header `Payvia` | `Provider` |

- [ ] **Step 1: Rename columns + index in migration 001**

In `migrations/001_CreateSubscriptionsTable.php` replace the four provider columns and the unique index. Also widen the price column to 191:

```php
            $table->string('provider_gateway', 50)->nullable();
            $table->string('provider_customer_id', 191)->nullable();
            $table->string('provider_subscription_id', 191)->nullable();
            $table->string('provider_price_id', 191)->nullable();
```
```php
            $table->unique(['provider_gateway', 'provider_subscription_id'], 'uniq_subscriptions_provider_sub');
```
And update the description string: `'Creates tenant subscriptions with optional payment-provider linkage.'`

- [ ] **Step 2: Rename columns + index in migrations 003 and 004**

`migrations/003_CreateSubscriptionEventsTable.php`:
```php
            $table->string('provider_gateway', 50)->nullable();
            $table->string('provider_logical_event_key', 191)->nullable();
```
```php
            $table->unique(['provider_gateway', 'provider_logical_event_key'], 'uniq_event_gateway_logical_key');
```
Description → `'Creates subscription lifecycle event log with provider logical-key dedupe.'`

`migrations/004_CreateSubscriptionPlansTable.php`:
```php
            $table->string('provider_price_id', 191)->nullable();
```

- [ ] **Step 3: Rename in repositories**

`SubscriptionRepository.php`: method `findByPayviaSubscription` → `findByProviderSubscription` (and its `$payviaSubscriptionId` param → `$providerSubscriptionId`); `where('payvia_gateway'…)`/`where('payvia_subscription_id'…)` → `provider_*`; `allWithPayvia` → `allWithProvider` with `whereRaw('provider_subscription_id IS NOT NULL')`.

`SubscriptionEventRepository.php`: in `existsByLogicalKey`, `where('payvia_gateway'…)`/`where('payvia_logical_event_key'…)` → `provider_*`.

`SubscriptionPlanRepository.php`: any `payvia_priced_plan_uuid` column reference → `provider_price_id`.

- [ ] **Step 3b: Checkpoint (schema + repos)**

Run: `composer analyze` — Expected: "No errors" (catches any repo method/signature mismatch like a renamed `findByProviderSubscription` with a stale caller). Note: `composer test` is expected RED until tests are renamed in Step 7 — do not run it as a gate yet.

- [ ] **Step 4: Rename in services / catalog / validator**

`SubscriptionService.php`: `start()` opts keys `payvia_gateway`/`payvia_customer_id`/`payvia_subscription_id` → `provider_*`; `payvia_priced_plan_uuid` opt → `provider_price_id`; the `$this->catalog->pricedPlanUuid(...)` call → `providerPriceId(...)`; in `reconcile()` the `payvia_gateway`/`payvia_subscription_id` reads → `provider_*`; the recorded `reconciled` event keys `payvia_gateway`/`payvia_logical_event_key` → `provider_*`. (The `pullProviderState` payvia branch is refactored later in Task 6 — leave it for now.)

`PlanCatalog.php`: method `pricedPlanUuid` → `providerPriceId`; read `$row['provider_price_id']`; config fallbacks `payvia_priced_plan_uuid`/`payvia_priced_plan` → single `provider_price_id`.

`PlanPayloadValidator.php`: payload key `payvia_priced_plan_uuid` → `provider_price_id` (lines ~37,74-76,100-101,109); method `validatePayviaPricedPlanUuid` → `validateProviderPriceId`. Generalize it (no 12-char rule):
```php
    private function validateProviderPriceId(mixed $value): ?string
    {
        return $this->nullableString($value, 'provider_price_id', 191);
    }
```
(`nullableString` already takes an optional max-length arg from the description-cap fix; if the 191 cap is exceeded it throws `provider_price_id must be 191 characters or fewer.`)

- [ ] **Step 5: Rename in the (soon-to-be-replaced) listener**

`src/Listeners/PaymentProviderEventListener.php`: column writes in `mapToSubscription` (`payvia_gateway`/`payvia_subscription_id` → `provider_*`); the `findByPayviaSubscription` call → `findByProviderSubscription`; the claim insert keys `payvia_gateway`/`payvia_logical_event_key` → `provider_*`; `'source' => 'payvia_event'` → `'provider_event'`; the relink-conflict + duplicate-claim log keys `payvia_*` → `provider_*`.

- [ ] **Step 6: Rename in HTTP/CLI/config**

`routes.php`: docblock fields `payvia_priced_plan_uuid:string="Optional Payvia priced-plan UUID"` → `provider_price_id:string="Optional provider price/plan identifier"` (both create and update blocks).
`CreatePlanCommand.php`/`UpdatePlanCommand.php`: option `payvia-priced-plan` → `provider-price-id`, description `'Provider price/plan identifier'`, payload key `payvia_priced_plan_uuid` → `provider_price_id`, the option-map key.
`ListPlansCommand.php`: column header `'Payvia'` → `'Provider'`; `$plan['payvia_priced_plan_uuid']` → `$plan['provider_price_id']`.
`ReconcileCommand.php`: comment + info string wording `payvia` → `provider` (`provider_subscription_id`, "provider-linked subscription(s)").
`config/subscriptions.php`: under `plans.pro`, `'payvia_priced_plan' => null` → `'provider_price_id' => null`.

- [ ] **Step 6b: Checkpoint (services + catalog + HTTP/CLI/config)**

Run: `composer analyze` — Expected: "No errors" (catches `pricedPlanUuid`→`providerPriceId` / `validateProviderPriceId` caller mismatches across catalog, validator, service, commands). Then run the cutover grep from Step 8 and eyeball that only the expected files (listener, soft-dep registration, fixtures) still contain `payvia`. Production code under `src/` (excluding the listener) and `config/` should be `payvia`-free at this point.

- [ ] **Step 7: Rename across all tests + support fakes**

Update every `tests/**` and `tests/Support/**` reference using the mapping table (column assertions, inserted rows, option names, method calls). Keep the payvia-shaped fake's class name (`FakePaymentProviderEvent`) — it emulates payvia's wrapper shape and is an adapter fixture (allowed by the cutover rule) — but any `provider_*` *data* it asserts uses the new names.

- [ ] **Step 8: Run the cutover grep (domain surfaces must be clean)**

Run:
```bash
grep -rin payvia src/ config/ migrations/ routes.php tests/ docs/ \
  | grep -viE 'PaymentProviderEventListener|FakePayvia|FakePaymentProviderEvent|Payvia\\\\Events|class_exists\(.*Payvia|// .*[Pp]ayvia|Payvia (adapter|event|installed|exists)'
```
Expected after this task: the only remaining matches are the listener file (renamed/replaced in Tasks 3–4), the payvia soft-dep registration in `SubscriptionsServiceProvider`, and payvia-adapter fixtures/comments. No `provider`-domain identifier should still read `payvia_*`.

- [ ] **Step 9: Run the suite**

Run: `composer test`
Expected: PASS, 200 tests (no count change — pure rename).

- [ ] **Step 10: phpcs + phpstan**

Run: `composer phpcs && composer analyze`
Expected: clean / "No errors".

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "refactor(subscriptions): rename payvia_* domain vocabulary to provider_*"
```

---

## Task 2: `ProviderSubscriptionEvent` DTO

**Files:**
- Create: `src/Projection/ProviderSubscriptionEvent.php`
- Test: `tests/Unit/Projection/ProviderSubscriptionEventTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Projection;

use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use PHPUnit\Framework\TestCase;

final class ProviderSubscriptionEventTest extends TestCase
{
    public function test_exposes_readonly_fields(): void
    {
        $event = new ProviderSubscriptionEvent(
            gateway: 'stripe',
            type: 'subscription.created',
            logicalEventKey: 'sub_1:created',
            normalized: ['gateway_subscription_id' => 'sub_1', 'status' => 'active'],
        );

        self::assertSame('stripe', $event->gateway);
        self::assertSame('subscription.created', $event->type);
        self::assertSame('sub_1:created', $event->logicalEventKey);
        self::assertSame('sub_1', $event->normalized['gateway_subscription_id']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Projection/ProviderSubscriptionEventTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the DTO**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Projection;

/**
 * Generic, provider-agnostic input to the subscription event projector.
 * Bridges (payvia or third-party) adapt their provider's event into this DTO.
 */
final class ProviderSubscriptionEvent
{
    /** @param array<string,mixed> $normalized */
    public function __construct(
        public readonly string $gateway,
        public readonly string $type,
        public readonly string $logicalEventKey,
        public readonly array $normalized,
    ) {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Projection/ProviderSubscriptionEventTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Projection/ProviderSubscriptionEvent.php tests/Unit/Projection/ProviderSubscriptionEventTest.php
git commit -m "feat(subscriptions): add ProviderSubscriptionEvent DTO"
```

---

## Task 3: `SubscriptionEventProjector` (move projection logic out of the listener)

**Files:**
- Create: `src/Contracts/SubscriptionEventProjectorInterface.php`
- Create: `src/Projection/SubscriptionEventProjector.php`
- Test: `tests/Integration/Projection/SubscriptionEventProjectorTest.php`

The projector is the former listener with the payvia-wrapper unwrap removed (it now receives the DTO directly) and the **unknown-type-now-claims** behavior change. After Task 1 the listener already writes `provider_*` columns and `source => 'provider_event'`, so the private helpers move verbatim.

- [ ] **Step 1: Write the interface**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Contracts;

use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;

interface SubscriptionEventProjectorInterface
{
    public function project(ProviderSubscriptionEvent $event): void;
}
```

- [ ] **Step 2: Write the failing projector test**

Port `tests/Integration/Listeners/PaymentProviderEventListenerTest.php` to drive the projector directly via a `ProviderSubscriptionEvent` (no payvia wrapper). Keep every existing case (idempotency claim, relink-conflict no-op, canceled-resurrection guard, period/grace, duplicate-claim swallow) with identical stored-state assertions. Each former listener-construction site becomes a `projector()` call; each `$listener(...)` dispatch becomes `$this->projector()->project(new ProviderSubscriptionEvent(...))`. Mirror the existing test's race-window pattern (it subclasses `SubscriptionEventRepository::existsByLogicalKey` — keep `SubscriptionEventRepository` non-final). Use only real `SubscriptionsTestCase` helpers:

```php
    private function projector(?SubscriptionEventRepository $events = null): SubscriptionEventProjector
    {
        return new SubscriptionEventProjector(
            new SubscriptionRepository(),
            $events ?? new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
        );
    }

    /** @return array<string,mixed>|null */
    private function row(string $tenant): ?array
    {
        return $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $tenant)->first();
    }

    /** @return list<array<string,mixed>> */
    private function eventsFor(string $tenant): array
    {
        return $this->connection()->table('subscription_events')->where('tenant_uuid', '=', $tenant)->get();
    }
```

Plus one NEW case for the intentional behavior change (unknown-but-mapped type now claims):

```php
    public function testUnknownTypeMappedToSubscriptionIsRecordedWithoutProjection(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_1',
        ]);

        $this->projector()->project(new ProviderSubscriptionEvent(
            gateway: 'stripe',
            type: 'subscription.trial_will_end', // not in the handled set
            logicalEventKey: 'sub_1:trial_will_end',
            normalized: ['gateway_subscription_id' => 'sub_1'],
        ));

        self::assertSame('active', $this->row('tenantA')['status']); // unchanged
        $events = $this->eventsFor('tenantA');
        self::assertCount(1, $events);                                 // but recorded
        self::assertSame('subscription.trial_will_end', $events[0]['type']);
    }
```
Add the imports: `SubscriptionEventProjector`, `ProviderSubscriptionEvent`, `SubscriptionRepository`, `SubscriptionEventRepository`, `PlanCatalog`.

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Projection/SubscriptionEventProjectorTest.php`
Expected: FAIL — projector class not found.

- [ ] **Step 4: Write the projector**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Projection;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Psr\Log\LoggerInterface;

/**
 * Owns all provider-event projection rules: claim-first idempotency, tenant
 * relink (unlinked-only), the status state machine, period/grace handling.
 * Provider-agnostic — consumes a ProviderSubscriptionEvent DTO.
 */
final class SubscriptionEventProjector implements SubscriptionEventProjectorInterface
{
    private const SETTLEABLE = ['trialing', 'past_due'];
    private const KNOWN_STATUSES = ['active', 'trialing', 'past_due', 'canceled', 'incomplete', 'paused'];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionEventRepository $events,
        private readonly PlanCatalog $catalog,
        private readonly ApplicationContext $context,
    ) {
    }

    public function project(ProviderSubscriptionEvent $event): void
    {
        $gateway = $event->gateway;
        $type = $event->type;
        $logicalKey = $event->logicalEventKey;
        $normalized = $event->normalized;

        if (
            $gateway !== ''
            && $logicalKey !== ''
            && $this->events->existsByLogicalKey($this->context, $gateway, $logicalKey)
        ) {
            return;
        }

        $sub = $this->mapToSubscription($gateway, $type, $normalized);
        if ($sub === null) {
            return; // unmapped provider subscription -> graceful no-op
        }

        // Behavior change vs. the old listener: an unknown-but-MAPPED type is
        // still claimed/recorded (empty change set) instead of returning early.
        $changes = $this->computeChanges($type, $sub, $normalized) ?? [];

        $from = isset($sub['status']) ? (string) $sub['status'] : null;
        $to = isset($changes['status']) ? (string) $changes['status'] : $from;

        try {
            db($this->context)->transaction(
                function () use ($sub, $changes, $gateway, $logicalKey, $from, $to, $type, $normalized): void {
                    $this->events->insertOrThrow($this->context, [
                        'tenant_uuid' => (string) $sub['tenant_uuid'],
                        'type' => $type,
                        'from_status' => $from,
                        'to_status' => $to,
                        'source' => 'provider_event',
                        'provider_gateway' => $gateway !== '' ? $gateway : null,
                        'provider_logical_event_key' => $logicalKey !== '' ? $logicalKey : null,
                        'data' => $normalized,
                    ]);

                    if ($changes !== []) {
                        $this->subscriptions->updateByTenant($this->context, (string) $sub['tenant_uuid'], $changes);
                    }
                }
            );
        } catch (\Throwable $e) {
            if ($this->events->isUniqueViolation($e)) {
                $this->resolveLogger()?->debug('Duplicate provider event claim skipped', [
                    'event' => 'subscriptions.duplicate_claim_skipped',
                    'provider_gateway' => $gateway,
                    'provider_logical_event_key' => $logicalKey,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);

                return;
            }
            throw $e;
        }
    }

    // ---- Move the following PRIVATE helpers VERBATIM from the former
    // PaymentProviderEventListener (post-Task-1 they already use provider_*):
    //   resolveLogger(): ?LoggerInterface
    //   mapToSubscription(string $gateway, string $type, array $normalized): ?array   (incl. relink-conflict guard)
    //   computeChanges(string $type, array $sub, array $normalized): ?array            (incl. canceled-resurrection guard)
    //   normalizedStatus(array $normalized): ?string
    //   periodChanges(array $normalized): array
    //   formatForDb(\DateTimeImmutable $dateTime): string
}
```

When moving `mapToSubscription`/`resolveLogger`, copy them exactly as they exist in the listener after Task 1 (they reference `$this->subscriptions`, `$this->events`, `$this->context`, `$this->catalog` — all present here).

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Projection/SubscriptionEventProjectorTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Contracts/SubscriptionEventProjectorInterface.php src/Projection/SubscriptionEventProjector.php tests/Integration/Projection/SubscriptionEventProjectorTest.php
git commit -m "feat(subscriptions): add SubscriptionEventProjector owning projection rules"
```

---

## Task 4: `PayviaSubscriptionEventBridge` + rewire (delete the listener)

**Files:**
- Create: `src/Bridge/PayviaSubscriptionEventBridge.php`
- Create: `tests/Integration/Bridge/PayviaSubscriptionEventBridgeTest.php`
- Modify: `src/SubscriptionsServiceProvider.php` (services + boot wiring)
- Delete: `src/Listeners/PaymentProviderEventListener.php`, `tests/Integration/Listeners/PaymentProviderEventListenerTest.php`

- [ ] **Step 1: Write the failing bridge test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Bridge;

use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use PHPUnit\Framework\TestCase;

/**
 * A spy projector that records the DTOs it receives. (Constructor property
 * promotion cannot be by-reference, so the spy holds its own public state.)
 */
final class SpyProjector implements SubscriptionEventProjectorInterface
{
    /** @var list<ProviderSubscriptionEvent> */
    public array $captured = [];

    public function project(ProviderSubscriptionEvent $event): void
    {
        $this->captured[] = $event;
    }
}

final class PayviaSubscriptionEventBridgeTest extends TestCase
{
    public function test_adapts_payvia_event_shape_into_one_projector_call(): void
    {
        $projector = new SpyProjector();

        // Payvia's wrapper: an object with ->event exposing the inner accessors.
        $inner = new class {
            public function gateway(): string { return 'stripe'; }
            public function type(): string { return 'subscription.created'; }
            public function logicalEventKey(): string { return 'sub_1:created'; }
            /** @return array<string,mixed> */
            public function normalized(): array { return ['gateway_subscription_id' => 'sub_1']; }
        };
        $payviaEvent = new class ($inner) { public function __construct(public object $event) {} };

        (new PayviaSubscriptionEventBridge($projector))($payviaEvent);

        self::assertCount(1, $projector->captured);
        self::assertSame('stripe', $projector->captured[0]->gateway);
        self::assertSame('subscription.created', $projector->captured[0]->type);
        self::assertSame('sub_1:created', $projector->captured[0]->logicalEventKey);
        self::assertSame('sub_1', $projector->captured[0]->normalized['gateway_subscription_id']);
    }

    public function test_ignores_event_without_inner_object(): void
    {
        $projector = new SpyProjector();
        (new PayviaSubscriptionEventBridge($projector))(new class { public ?object $event = null; });
        self::assertCount(0, $projector->captured);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Bridge/PayviaSubscriptionEventBridgeTest.php`
Expected: FAIL — bridge class not found.

- [ ] **Step 3: Write the bridge**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Bridge;

use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;

/**
 * Thin first-party adapter: maps payvia's PaymentProviderEvent (a wrapper whose
 * `->event` exposes gateway()/type()/logicalEventKey()/normalized()) into the
 * generic ProviderSubscriptionEvent and hands it to the projector. Owns NO
 * projection rules. The ONLY subscriptions class permitted to name payvia.
 */
final class PayviaSubscriptionEventBridge
{
    public function __construct(private readonly SubscriptionEventProjectorInterface $projector)
    {
    }

    public function __invoke(object $payviaEvent): void
    {
        $inner = $payviaEvent->event ?? null;
        if (!is_object($inner)) {
            return;
        }

        $this->projector->project(new ProviderSubscriptionEvent(
            gateway: (string) $inner->gateway(),
            type: (string) $inner->type(),
            logicalEventKey: (string) $inner->logicalEventKey(),
            normalized: (array) $inner->normalized(),
        ));
    }
}
```

- [ ] **Step 4: Rewire the service provider**

In `src/SubscriptionsServiceProvider.php`:

Replace the `PaymentProviderEventListener` import with the new classes:
```php
use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
```

In `services()`, replace the `PaymentProviderEventListener::class` definition with the projector binding plus the bridge service:
```php
            SubscriptionEventProjectorInterface::class => [
                'class' => SubscriptionEventProjector::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Registered as a service so the '@serviceId' lazy listener resolves.
            PayviaSubscriptionEventBridge::class => [
                'class' => PayviaSubscriptionEventBridge::class,
                'shared' => true,
                'autowire' => true,
            ],
```

In `boot()`, change the payvia listener registration to point at the bridge:
```php
            if (class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class)) {
                app($context, \Glueful\Events\EventService::class)->addListener(
                    \Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class,
                    '@' . PayviaSubscriptionEventBridge::class
                );
            }
```

- [ ] **Step 5: Delete the old listener and its test**

```bash
git rm src/Listeners/PaymentProviderEventListener.php tests/Integration/Listeners/PaymentProviderEventListenerTest.php
```
(All its cases now live in `SubscriptionEventProjectorTest` and `PayviaSubscriptionEventBridgeTest`.)

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS. Net test count ≈ 200 − (old listener cases) + (projector cases ported + 1 new) + (2 bridge cases) + (1 DTO) — confirm no case was dropped; the projector test must contain every former listener assertion.

- [ ] **Step 7: phpcs + phpstan, then commit**

Run: `composer phpcs && composer analyze`
```bash
git add -A
git commit -m "feat(subscriptions): replace payvia listener with thin bridge over the projector"
```

---

## Task 5: `ProviderStatePullerInterface` + payvia puller + reconcile refactor

**Files:**
- Create: `src/Contracts/ProviderStatePullerInterface.php`
- Create: `src/Bridge/PayviaProviderStatePuller.php`
- Modify: `src/SubscriptionService.php` (constructor + `pullProviderState`)
- Modify: `src/SubscriptionsServiceProvider.php` (puller binding + factory wiring)
- Test: `tests/Integration/SubscriptionReconcileTest.php` (retarget onto the interface)

- [ ] **Step 1: Write the interface**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Contracts;

interface ProviderStatePullerInterface
{
    /**
     * Pull authoritative provider state for a subscription.
     *
     * @return array<string,mixed>|null Normalized state, or null when unavailable.
     */
    public function pull(string $gateway, string $providerSubscriptionId): ?array;
}
```

- [ ] **Step 2: Add a `CallablePuller` test wrapper and update the reconcile test's `service()` helper**

The existing `SubscriptionReconcileTest` builds the service via `service(?callable $puller = null)` and passes closures (`static fn(): array => [...]`). When the constructor switches to `?ProviderStatePullerInterface` (Step 4), those closures no longer satisfy the type. Add a tiny support wrapper so the existing closure-style test bodies stay UNCHANGED.

Create `tests/Support/CallablePuller.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;

final class CallablePuller implements ProviderStatePullerInterface
{
    /** @param callable(string,string):(array<string,mixed>|null) $fn */
    public function __construct(private $fn)
    {
    }

    public function pull(string $gateway, string $providerSubscriptionId): ?array
    {
        return ($this->fn)($gateway, $providerSubscriptionId);
    }
}
```

Update `SubscriptionReconcileTest::service()` to wrap a callable into the interface (keep the closure-friendly signature so the other 6 cases are untouched):
```php
    private function service(?callable $puller = null): SubscriptionService
    {
        return new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            $puller === null ? null : new CallablePuller($puller),
        );
    }
```

Add one case that injects a real interface implementation directly (proving the seam isn't closure-specific):
```php
    public function testReconcileAppliesDriftFromInterfacePuller(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'past_due',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_1',
        ]);

        $puller = new class implements ProviderStatePullerInterface {
            public function pull(string $gateway, string $providerSubscriptionId): ?array
            {
                return ['status' => 'active', 'current_period_end' => '2030-01-01 00:00:00'];
            }
        };

        $service = new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            $puller,
        );
        $row = $service->reconcile('tenantA');

        self::assertSame('active', $row['status']);
        self::assertCount(1, $this->eventsFor('tenantA'));
    }
```
Add `use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;` and `use Glueful\Extensions\Subscriptions\Tests\Support\CallablePuller;`.

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/SubscriptionReconcileTest.php`
Expected: FAIL — `ProviderStatePullerInterface` not found / constructor 5th arg type mismatch.

- [ ] **Step 4: Refactor `SubscriptionService`**

Change the constructor to depend on the interface (replacing `?callable $providerStatePuller`):
```php
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionEventRepository $events,
        private readonly PlanCatalog $catalog,
        private readonly ApplicationContext $context,
        private readonly ?ProviderStatePullerInterface $puller = null,
    ) {
    }
```
Replace `pullProviderState()` (drop the `\Closure` field and the inline `class_exists(Payvia...)` branch) with:
```php
    /** @return array<string,mixed>|null */
    private function pullProviderState(string $gateway, string $providerSubscriptionId): ?array
    {
        return $this->puller?->pull($gateway, $providerSubscriptionId);
    }
```
Add `use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;` and remove the now-unused `\Closure` import/property.

- [ ] **Step 5: Write the payvia puller**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;

/**
 * Payvia-backed default puller. Bound to ProviderStatePullerInterface only when
 * payvia's GatewaySubscriptionService exists. The ONLY reconcile class that
 * names payvia.
 */
final class PayviaProviderStatePuller implements ProviderStatePullerInterface
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function pull(string $gateway, string $providerSubscriptionId): ?array
    {
        try {
            $service = app($this->context, \Glueful\Extensions\Payvia\Services\GatewaySubscriptionService::class);

            $state = $service->reconcile($gateway, $providerSubscriptionId);

            return is_array($state) ? $state : null;
        } catch (\Throwable) {
            return null; // provider/service failure degrades to "no drift applied"
        }
    }
}
```

- [ ] **Step 6: Wire the puller in the service provider**

`$this->app` is a read-only PSR-11 `ContainerInterface` (only `get()`/`has()`), so bind the interface through the static `services()` DSL — conditionally, gated on payvia existing (same `class_exists` pattern the boot guard uses; payvia classes are autoloadable at DI-compile time when installed). Change `services()` to build its array and append the payvia binding only when payvia is present:

```php
    public static function services(): array
    {
        $defs = [
            // ... all existing definitions ...
            PayviaProviderStatePuller::class => [
                'class' => PayviaProviderStatePuller::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];

        // Bind the reconcile puller to the payvia implementation ONLY when payvia
        // is installed. Absent payvia, ProviderStatePullerInterface stays unbound
        // and SubscriptionService resolves a null puller (reconcile no-ops).
        if (class_exists(\Glueful\Extensions\Payvia\Services\GatewaySubscriptionService::class)) {
            $defs[ProviderStatePullerInterface::class] = [
                'class' => PayviaProviderStatePuller::class,
                'shared' => true,
                'autowire' => true,
            ];
        }

        return $defs;
    }
```

Update `makeSubscriptionService()` to resolve the puller explicitly (never autowire a nullable unbound interface):
```php
    public static function makeSubscriptionService(ContainerInterface $c): SubscriptionService
    {
        $puller = $c->has(ProviderStatePullerInterface::class)
            ? $c->get(ProviderStatePullerInterface::class)
            : null;

        return new SubscriptionService(
            $c->get(SubscriptionRepository::class),
            $c->get(SubscriptionEventRepository::class),
            $c->get(PlanCatalog::class),
            $c->get(ApplicationContext::class),
            $puller,
        );
    }
```
Add imports for `ProviderStatePullerInterface` and `PayviaProviderStatePuller`. No `boot()` change is needed for the puller (the binding is fully handled in `services()`).

**Why the concrete `PayviaProviderStatePuller` is registered unconditionally (only the interface binding is gated):** the class's constructor depends only on `ApplicationContext` — it names payvia solely via a string FQCN passed to `app(...)` *inside* `pull()` (Step 5), so the class autoloads and constructs fine even with payvia absent. Registering the concrete service unconditionally is therefore safe; what must be gated is the `ProviderStatePullerInterface` → puller binding, because absent payvia there is no provider to pull from and reconcile should no-op (null puller). If a third-party provider ships its own puller, it binds `ProviderStatePullerInterface` itself and this payvia binding simply isn't added.

- [ ] **Step 7: Run reconcile test, then full suite**

Run: `vendor/bin/phpunit tests/Integration/SubscriptionReconcileTest.php` then `composer test`
Expected: PASS.

- [ ] **Step 8: phpcs + phpstan, then commit**

Run: `composer phpcs && composer analyze`
```bash
git add -A
git commit -m "feat(subscriptions): drive reconcile through ProviderStatePullerInterface"
```

---

## Task 6: BYOP example test (proves no payvia dependency)

**Files:**
- Create: `tests/Integration/Byop/CustomProviderExampleTest.php`

- [ ] **Step 1: Write the example test**

A self-contained custom provider: a fake event class, a hand-written bridge calling the projector, and a fake puller — asserting subscription state updates with no payvia classes referenced.

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Byop;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

final class CustomProviderExampleTest extends SubscriptionsTestCase
{
    public function testCustomProviderDrivesSubscriptionStateWithoutPayvia(): void
    {
        self::assertFalse(
            class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class, false),
            'BYOP example must not rely on payvia being loaded'
        );

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'past_due',
            'provider_gateway' => 'acme',
            'provider_subscription_id' => 'acme_1',
        ]);

        // A custom bridge would build the projector exactly like this and call it:
        $projector = new SubscriptionEventProjector(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
        );
        $projector->project(new ProviderSubscriptionEvent(
            gateway: 'acme',
            type: 'payment.succeeded',
            logicalEventKey: 'acme_1:paid:1',
            normalized: ['gateway_subscription_id' => 'acme_1', 'current_period_end' => '2030-01-01 00:00:00'],
        ));

        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', '=', 'tenantA')->first();
        self::assertSame('active', $row['status']); // settled from past_due

        // And a custom puller drives reconcile:
        $puller = new class implements ProviderStatePullerInterface {
            public function pull(string $gateway, string $providerSubscriptionId): ?array
            {
                return ['status' => 'canceled'];
            }
        };
        $service = new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            $puller,
        );
        $service->reconcile('tenantA');

        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', '=', 'tenantA')->first();
        self::assertSame('canceled', $row['status']);
    }
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit tests/Integration/Byop/CustomProviderExampleTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Byop/CustomProviderExampleTest.php
git commit -m "test(subscriptions): prove custom provider works with no payvia"
```

---

## Task 7: BYOP docs

**Files:**
- Create: `docs/BRING_YOUR_OWN_PROVIDER.md`

- [ ] **Step 1: Write the guide**

Document: (1) the `SubscriptionEventProjectorInterface` + `ProviderSubscriptionEvent` contract; (2) the normalized event vocabulary from spec §6 (`type` values, `normalized` keys, `logicalEventKey`, `metadata.tenant_uuid` as recovery hint only); (3) registering your own bridge listener for your provider's event class; (4) the optional `ProviderStatePullerInterface` for reconcile; (5) the worked example mirroring `CustomProviderExampleTest`. State that payvia is the first-party default and needs no wiring.

- [ ] **Step 2: Commit**

```bash
git add docs/BRING_YOUR_OWN_PROVIDER.md
git commit -m "docs(subscriptions): add bring-your-own-provider guide"
```

---

## Task 8: Cutover verification + CHANGELOG

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Run the cutover acceptance grep**

```bash
grep -rin payvia src/ config/ migrations/ routes.php tests/ docs/ \
  | grep -viE 'Bridge/Payvia|PayviaSubscriptionEventBridge|PayviaProviderStatePuller|class_exists\(.*Payvia|Payvia\\\\(Events|Services)|// .*[Pp]ayvia|[Pp]ayvia (adapter|event|installed|exists|default)|FakePayvia|FakePaymentProviderEvent'
```
Expected: **no matches**. Any remaining hit is either a real miss (fix it) or a legitimately payvia-specific reference (extend the filter and note why). The bridge/puller class files, their soft-dep registration, payvia-adapter fixtures, and payvia-naming comments are the only allowed `payvia` mentions.

- [ ] **Step 2: Update CHANGELOG**

Under `## Unreleased`, add:
```markdown
### Added
- Provider-agnostic subscription event projection: `SubscriptionEventProjectorInterface` + `ProviderSubscriptionEvent` DTO, and `ProviderStatePullerInterface` for reconcile. Third-party payment providers can drive subscription state without payvia. See `docs/BRING_YOUR_OWN_PROVIDER.md`.

### Changed
- Payvia integration is now a thin optional bridge (`PayviaSubscriptionEventBridge`) + puller (`PayviaProviderStatePuller`); all subscriptions-owned `payvia_*` storage/options/config/CLI vocabulary renamed to `provider_*` (`provider_gateway`, `provider_customer_id`, `provider_subscription_id`, `provider_price_id`, `provider_logical_event_key`). No backward-compat shim (extension is pre-release).
- An unknown provider event type that maps to an existing subscription is now recorded (idempotency claim) with no projection, instead of being dropped before the claim.
```

- [ ] **Step 3: Final full suite + static analysis**

Run: `composer test && composer phpcs && composer analyze`
Expected: PASS / clean.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs(subscriptions): record BYOP architecture and provider_* rename in changelog"
```

---

## Self-Review (completed during planning)

- **Spec coverage:** generic projector boundary (Tasks 2–3), projection logic moved out of listener (Task 3), thin payvia bridge (Task 4), third-party path via own bridge listener (Tasks 4/6, no registry), reconcile puller seam + payvia default (Task 5), `provider_*` rename incl. `provider_price_id` with no `provider_priced_plan_uuid` (Task 1), optional-puller construction via `has()?get():null` (Task 5 Step 6), unknown-type-claims behavior change (Task 3), BYOP docs + no-payvia example (Tasks 6–7), cutover grep gate (Task 8). Deferred items (correlation token, config registry) intentionally absent.
- **Placeholders:** none — every new file has full code; renames are exact identifier mappings; the one "move verbatim" instruction (Task 3 private helpers) references concrete already-existing methods, not unwritten code.
- **Type consistency:** `ProviderSubscriptionEvent(gateway,type,logicalEventKey,normalized)`, `SubscriptionEventProjectorInterface::project(ProviderSubscriptionEvent)`, `ProviderStatePullerInterface::pull(string,string)`, `PayviaSubscriptionEventBridge::__invoke(object)`, `makeSubscriptionService` 5th arg `?ProviderStatePullerInterface` — consistent across tasks.
- **Container binding resolved:** `$this->app` is read-only PSR-11; the payvia puller binding is registered conditionally in the static `services()` DSL via `class_exists` (Task 5 Step 6), not a runtime `set()`. No open verification items remain.
