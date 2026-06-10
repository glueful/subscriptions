<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 6.2 -- the full no-payvia lifecycle (free/trial/comp): every path here runs
 * with no payvia class present.
 */
final class SubscriptionServiceTest extends SubscriptionsTestCase
{
    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext()
        );
    }

    /** @return list<array<string,mixed>> */
    private function eventsFor(string $tenantUuid): array
    {
        return $this->connection()->table('subscription_events')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->get();
    }

    public function testStartDefaultsToActiveWithNullPayvia(): void
    {
        $row = $this->service->start('tenantA', 'free');

        self::assertSame('free', $row['plan_key']);
        self::assertSame('active', $row['status']);
        self::assertNotEmpty($row['uuid']);

        $stored = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantA')->first();
        self::assertIsArray($stored);
        self::assertSame('free', $stored['plan_key']);
        self::assertSame('active', $stored['status']);
        self::assertNull($stored['payvia_gateway']);
        self::assertNull($stored['payvia_customer_id']);
        self::assertNull($stored['payvia_subscription_id']);

        $events = $this->eventsFor('tenantA');
        self::assertCount(1, $events);
        self::assertSame('created', $events[0]['type']);
        self::assertSame('manual', $events[0]['source']);
        self::assertSame('active', $events[0]['to_status']);
        self::assertNull($events[0]['payvia_logical_event_key']);
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
        self::assertNull($stored['payvia_subscription_id']);
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
}
