<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\CallablePuller;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

/**
 * FACADE EQUIVALENCE (spec §11.2): "a 1.x-shaped test copied from the current
 * suite must pass unmodified against 2.0".
 *
 * Every test method below is a VERBATIM copy of a 1.x lifecycle test method as it
 * stood at the commit before the subject-model activation -- the full
 * SubscriptionServiceTest lifecycle suite plus the SubscriptionReconcileTest drift
 * suite. Nothing inside a test body was touched: not an assertion, not a fixture,
 * not a plan key. Only the construction wiring below differs, because the
 * constructor gained the SubjectResolverInterface seam.
 *
 * (The one 1.x method deliberately not copied is
 * SubscriptionReconcileTest::testReconcileAppliesDriftFromInterfacePuller, which
 * builds a SubscriptionService inside its own body -- it is a constructor-seam
 * test, not a lifecycle test, and it still lives in its original file.)
 *
 * DO NOT "improve" this file. Its entire value is that it was not edited.
 */
final class SubscriptionServiceFacadeTest extends SubscriptionsTestCase
{
    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->service();
    }

    private function service(?callable $puller = null): SubscriptionService
    {
        return new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            new DefaultSubjectResolver(),
            $puller === null ? null : new CallablePuller($puller),
        );
    }

    /** @return list<array<string,mixed>> */
    private function eventsFor(string $tenantUuid): array
    {
        return $this->connection()->table('subscription_events')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->get();
    }

    public function testStartDefaultsToActiveWithNullProvider(): void
    {
        $row = $this->service->start('tenantA', 'free');

        self::assertSame('free', $row['plan_key']);
        self::assertSame('active', $row['status']);
        self::assertNotEmpty($row['uuid']);

        $stored = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantA')->first();
        self::assertIsArray($stored);
        self::assertSame('free', $stored['plan_key']);
        self::assertSame('active', $stored['status']);
        self::assertNull($stored['provider_gateway']);
        self::assertNull($stored['provider_customer_id']);
        self::assertNull($stored['provider_subscription_id']);

        $events = $this->eventsFor('tenantA');
        self::assertCount(1, $events);
        self::assertSame('created', $events[0]['type']);
        self::assertSame('manual', $events[0]['source']);
        self::assertSame('active', $events[0]['to_status']);
        self::assertNull($events[0]['provider_logical_event_key']);
    }

    public function testStartTrialingFromOpts(): void
    {
        $this->service->start('tenantB', 'pro', [
            'status' => 'trialing',
            'trial_ends_at' => '2026-07-01 00:00:00',
        ]);

        $stored = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantB')->first();
        self::assertIsArray($stored);
        self::assertSame('pro', $stored['plan_key']);
        self::assertSame('trialing', $stored['status']);
        self::assertSame('2026-07-01 00:00:00', $stored['trial_ends_at']);
        self::assertNull($stored['provider_subscription_id']);
    }

    public function testStartAcceptsActiveDbPlan(): void
    {
        $this->seedManagedPlan('team', 'active');

        $row = $this->service->start('tenantTeam', 'team');

        self::assertSame('team', $row['plan_key']);
    }

    public function testStartRejectsDraftDbPlan(): void
    {
        $this->seedManagedPlan('future', 'draft');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->start('tenantFuture', 'future');
    }

    public function testStartRejectsArchivedDbPlan(): void
    {
        $this->seedManagedPlan('legacy', 'archived');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->start('tenantLegacy', 'legacy');
    }

    public function testCurrentReturnsRowOrNull(): void
    {
        self::assertNull($this->service->current('tenantA'));

        $this->service->start('tenantA', 'free');

        $row = $this->service->current('tenantA');
        self::assertIsArray($row);
        self::assertSame('tenantA', $row['tenant_uuid']);
    }

    public function testChangePlanUpdatesAndAppendsPlanChangedEvent(): void
    {
        $this->service->start('tenantA', 'free');

        $row = $this->service->changePlan('tenantA', 'pro');

        self::assertSame('pro', $row['plan_key']);

        $events = $this->eventsFor('tenantA');
        self::assertCount(2, $events);
        self::assertSame('plan_changed', $events[1]['type']);
        self::assertSame('manual', $events[1]['source']);
        self::assertSame(
            ['from_plan' => 'free', 'to_plan' => 'pro'],
            json_decode((string) $events[1]['data'], true)
        );
    }

    public function testChangePlanRejectsDraftDbPlan(): void
    {
        $this->service->start('tenantA', 'free');
        $this->seedManagedPlan('future', 'draft');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->changePlan('tenantA', 'future');
    }

    public function testChangePlanRejectsArchivedDbPlan(): void
    {
        $this->service->start('tenantA', 'free');
        $this->seedManagedPlan('legacy', 'archived');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->changePlan('tenantA', 'legacy');
    }

    public function testCancelImmediateSetsCanceledStatusAndTimestamp(): void
    {
        $this->service->start('tenantA', 'pro');

        $row = $this->service->cancel('tenantA', atPeriodEnd: false);

        self::assertSame('canceled', $row['status']);
        self::assertNotEmpty($row['canceled_at']);

        $events = $this->eventsFor('tenantA');
        self::assertCount(2, $events);
        self::assertSame('canceled', $events[1]['type']);
        self::assertSame('active', $events[1]['from_status']);
        self::assertSame('canceled', $events[1]['to_status']);
    }

    public function testCancelAtPeriodEndKeepsStatusAndFlagsMetadata(): void
    {
        $this->service->start('tenantA', 'pro');

        $row = $this->service->cancel('tenantA');

        self::assertSame('active', $row['status']);
        self::assertNull($row['canceled_at']);

        $metadata = json_decode((string) $row['metadata'], true);
        self::assertIsArray($metadata);
        self::assertTrue($metadata['cancel_at_period_end']);

        $events = $this->eventsFor('tenantA');
        self::assertCount(2, $events);
        self::assertSame('canceled', $events[1]['type']);
        self::assertSame('active', $events[1]['to_status']);
    }

    public function testReconcileStubReturnsCurrent(): void
    {
        $this->service->start('tenantA', 'free');

        $row = $this->service->reconcile('tenantA');

        self::assertIsArray($row);
        self::assertSame('tenantA', $row['tenant_uuid']);
        self::assertSame('free', $row['plan_key']);
    }

    public function testReconcileWithoutProviderLinkIsNoOp(): void
    {
        // Free/comp subscription -- no provider_subscription_id, and NO payvia
        // installed in this suite: must not throw, must return the row unchanged.
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free', 'status' => 'active']);

        $row = $this->service()->reconcile('tenantA');

        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
        self::assertSame('free', $row['plan_key']);
        self::assertCount(0, $this->eventsFor('tenantA'));
    }

    public function testReconcileForUnknownTenantReturnsNull(): void
    {
        self::assertNull($this->service()->reconcile('ghost'));
    }

    public function testReconcileAppliesDriftAndAppendsReconciledEvent(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $pulled = [];
        $puller = function (string $gateway, string $gwSubId) use (&$pulled): array {
            $pulled[] = [$gateway, $gwSubId];

            return ['status' => 'past_due', 'current_period_end' => '2026-06-30 00:00:00'];
        };

        $row = $this->service($puller)->reconcile('tenantA');

        self::assertSame([['paystack', 'sub_X']], $pulled);
        self::assertIsArray($row);
        self::assertSame('past_due', $row['status']);
        self::assertSame('2026-06-30 00:00:00', $row['current_period_end']);

        $events = $this->eventsFor('tenantA');
        self::assertCount(1, $events);
        self::assertSame('reconciled', $events[0]['type']);
        self::assertSame('reconcile', $events[0]['source']);
        self::assertNull($events[0]['provider_logical_event_key']);
        self::assertSame('active', $events[0]['from_status']);
        self::assertSame('past_due', $events[0]['to_status']);
    }

    public function testReconcileEnteringPastDueGrantsDunningGrace(): void
    {
        // Drifting to past_due must grant the SAME dunning grace the webhook
        // path grants (now + grace_days) -- not downgrade the tenant instantly.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $row = $this->service(static fn(): array => ['status' => 'past_due'])->reconcile('tenantA');

        self::assertIsArray($row);
        self::assertSame('past_due', $row['status']);
        self::assertNotEmpty($row['grace_ends_at']);

        // grace_ends_at ~= now + grace_days (3 in the shipped config)
        $grace = new \DateTimeImmutable((string) $row['grace_ends_at']);
        $expected = new \DateTimeImmutable('+3 days');
        self::assertLessThan(120, abs($grace->getTimestamp() - $expected->getTimestamp()));
    }

    public function testReconcileAlreadyPastDueDoesNotReExtendGrace(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $service = $this->service(static fn(): array => ['status' => 'past_due']);
        $service->reconcile('tenantA');

        // Plant a sentinel grace so ANY re-extension (which would recompute
        // now + grace_days) is observable -- same principle as the listener.
        $sentinel = '2030-01-01 00:00:00';
        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', 'tenantA')
            ->update(['grace_ends_at' => $sentinel]);

        $row = $service->reconcile('tenantA');

        self::assertIsArray($row);
        self::assertSame('past_due', $row['status']);
        self::assertSame($sentinel, $row['grace_ends_at']);
        self::assertCount(1, $this->eventsFor('tenantA')); // only the first drift
    }

    public function testReconcileWithNoDriftAppendsNoEvent(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $row = $this->service(static fn(): array => ['status' => 'active'])->reconcile('tenantA');

        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
        self::assertCount(0, $this->eventsFor('tenantA'));
    }

    public function testReconcilePullerReturningNullIsNoOp(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $row = $this->service(static fn(): ?array => null)->reconcile('tenantA');

        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
        self::assertCount(0, $this->eventsFor('tenantA'));
    }

    private function seedManagedPlan(string $planKey, string $status): void
    {
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => Utils::generateNanoID(12),
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'description' => null,
            'entitlements' => json_encode(['projects.limit' => 25], JSON_THROW_ON_ERROR),
            'provider_price_id' => null,
            'status' => $status,
            'sort_order' => 10,
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
        ]);
    }
}
