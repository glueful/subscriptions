<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\CallablePuller;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 6.4 -- reconcile pulls authoritative provider state through the injectable
 * puller seam; with no provider installed (this suite) the default seam resolves
 * to null and reconcile is a safe no-op.
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

    public function testReconcileAppliesDriftFromInterfacePuller(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'past_due',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_1',
        ]);

        // A hand-rolled interface impl (not the CallablePuller shim) that records
        // the args it was called with, so this case proves the interface seam end
        // to end rather than overlapping the closure-based drift tests.
        $puller = new class implements ProviderStatePullerInterface {
            /** @var list<array{string,string}> */
            public array $calls = [];

            public function pull(string $gateway, string $providerSubscriptionId): ?array
            {
                $this->calls[] = [$gateway, $providerSubscriptionId];

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

        // The puller received the subscription's provider linkage.
        self::assertSame([['stripe', 'sub_1']], $puller->calls);

        // Drift (status + period) was applied to the row...
        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
        self::assertSame('2030-01-01 00:00:00', $row['current_period_end']);

        // ...and a single reconciled event records the transition.
        $events = $this->eventsFor('tenantA');
        self::assertCount(1, $events);
        self::assertSame('reconciled', $events[0]['type']);
        self::assertSame('reconcile', $events[0]['source']);
        self::assertSame('past_due', $events[0]['from_status']);
        self::assertSame('active', $events[0]['to_status']);
    }
}
