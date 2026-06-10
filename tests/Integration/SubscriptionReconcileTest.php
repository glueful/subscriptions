<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 6.4 -- reconcile pulls authoritative provider state through the injectable
 * puller seam; with payvia absent (this suite) the default seam resolves to null
 * and reconcile is a safe no-op.
 */
final class SubscriptionReconcileTest extends SubscriptionsTestCase
{
    private function service(?callable $puller = null): SubscriptionService
    {
        return new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            $puller
        );
    }

    /** @return list<array<string,mixed>> */
    private function eventsFor(string $tenantUuid): array
    {
        return $this->connection()->table('subscription_events')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->get();
    }

    public function testReconcileWithoutPayviaLinkIsNoOp(): void
    {
        // Free/comp subscription -- no payvia_subscription_id, and NO payvia
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
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
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
        self::assertNull($events[0]['payvia_logical_event_key']);
        self::assertSame('active', $events[0]['from_status']);
        self::assertSame('past_due', $events[0]['to_status']);
    }

    public function testReconcileWithNoDriftAppendsNoEvent(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
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
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
        ]);

        $row = $this->service(static fn(): ?array => null)->reconcile('tenantA');

        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
        self::assertCount(0, $this->eventsFor('tenantA'));
    }
}
