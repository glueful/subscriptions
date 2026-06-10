<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Listeners;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Listeners\PaymentProviderEventListener;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\FakePaymentProviderEvent;
use Glueful\Extensions\Subscriptions\Tests\Support\FakeProviderEvent;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 6.3 -- claim-first projection of payvia provider events (S6/S7).
 * Runs with NO payvia installed: events are in-suite duck-typed fakes.
 */
final class PaymentProviderEventListenerTest extends SubscriptionsTestCase
{
    private PaymentProviderEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = new PaymentProviderEventListener(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext()
        );
    }

    /** @param array<string,mixed> $normalized */
    private function dispatch(string $type, string $logicalKey, array $normalized, string $gateway = 'paystack'): void
    {
        ($this->listener)(new FakePaymentProviderEvent(
            new FakeProviderEvent($gateway, $type, $logicalKey, $normalized)
        ));
    }

    /** @return array<string,mixed> */
    private function subscription(string $tenantUuid = 'tenantA'): array
    {
        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', $tenantUuid)->first();
        self::assertIsArray($row);

        return $row;
    }

    private function eventCount(): int
    {
        return count($this->connection()->table('subscription_events')->get());
    }

    public function testPastDueProjectsStatusAndSetsGrace(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
        ]);

        $this->dispatch('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X']);

        $row = $this->subscription();
        self::assertSame('past_due', $row['status']);
        self::assertNotEmpty($row['grace_ends_at']);

        // grace_ends_at ~= now + grace_days (3 in the shipped config)
        $grace = new \DateTimeImmutable((string) $row['grace_ends_at']);
        $expected = new \DateTimeImmutable('+3 days');
        self::assertLessThan(120, abs($grace->getTimestamp() - $expected->getTimestamp()));

        $events = $this->connection()->table('subscription_events')->get();
        self::assertCount(1, $events);
        self::assertSame('payvia_event', $events[0]['source']);
        self::assertSame('paystack', $events[0]['payvia_gateway']);
        self::assertSame('k1', $events[0]['payvia_logical_event_key']);
        self::assertSame('trialing', $events[0]['from_status']);
        self::assertSame('past_due', $events[0]['to_status']);
    }

    public function testDuplicateEventNeverReprojectsNorExtendsGrace(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
        ]);

        $this->dispatch('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X']);

        // Plant a sentinel grace so ANY re-projection (which would recompute
        // now + grace_days) is observable -- the dup must not touch it.
        $sentinel = '2030-01-01 00:00:00';
        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', 'tenantA')
            ->update(['grace_ends_at' => $sentinel]);

        $this->dispatch('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X']);

        $row = $this->subscription();
        self::assertSame('past_due', $row['status']);
        self::assertSame($sentinel, $row['grace_ends_at']);
        self::assertSame(1, $this->eventCount());
    }

    public function testPaymentSucceededSettlesPastDueAndClearsGrace(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'past_due',
            'grace_ends_at' => '2026-06-13 00:00:00',
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
        ]);

        $this->dispatch('payment.succeeded', 'k2', ['gateway_subscription_id' => 'sub_X']);

        $row = $this->subscription();
        self::assertSame('active', $row['status']);
        self::assertNull($row['grace_ends_at']);

        $events = $this->connection()->table('subscription_events')->get();
        self::assertCount(1, $events);
        self::assertSame('past_due', $events[0]['from_status']);
        self::assertSame('active', $events[0]['to_status']);
        self::assertSame('k2', $events[0]['payvia_logical_event_key']);
    }

    public function testUnmappedGatewaySubscriptionIdNoOps(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
        ]);

        $this->dispatch('subscription.past_due', 'k9', ['gateway_subscription_id' => 'sub_GHOST']);

        self::assertSame('active', $this->subscription()['status']);
        self::assertSame(0, $this->eventCount());
    }

    public function testGatewayScopedMapping(): void
    {
        // Same provider-sub id on a DIFFERENT gateway must not match (per-gateway map).
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'payvia_gateway' => 'stripe',
            'payvia_subscription_id' => 'sub_X',
        ]);

        $this->dispatch('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X'], gateway: 'paystack');

        self::assertSame('active', $this->subscription()['status']);
        self::assertSame(0, $this->eventCount());
    }

    public function testSubscriptionCreatedRecoversLinkFromMetadataTenantUuid(): void
    {
        // Tenant exists but has never been payvia-linked (e.g. started via checkout).
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->dispatch('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ]);

        $row = $this->subscription();
        self::assertSame('paystack', $row['payvia_gateway']);
        self::assertSame('sub_NEW', $row['payvia_subscription_id']);
        self::assertSame('active', $row['status']);
        self::assertSame(1, $this->eventCount());
    }

    public function testSubscriptionCanceledProjectsCanceledAt(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
        ]);

        $this->dispatch('subscription.canceled', 'k3', ['gateway_subscription_id' => 'sub_X']);

        $row = $this->subscription();
        self::assertSame('canceled', $row['status']);
        self::assertNotEmpty($row['canceled_at']);
    }
}
