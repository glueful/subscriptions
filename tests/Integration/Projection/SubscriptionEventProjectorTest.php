<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Projection;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\CapturingLogger;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Claim-first projection of provider events (S6/S7), driven directly through the
 * generic ProviderSubscriptionEvent DTO (no payvia wrapper). Ports every case from
 * the former PaymentProviderEventListenerTest plus the unknown-type-claims case.
 */
final class SubscriptionEventProjectorTest extends SubscriptionsTestCase
{
    private function projector(?SubscriptionEventRepository $events = null): SubscriptionEventProjector
    {
        return new SubscriptionEventProjector(
            new SubscriptionRepository(),
            $events ?? new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
        );
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private function project(string $type, string $logicalKey, array $normalized, string $gateway = 'paystack'): void
    {
        $this->projector()->project(new ProviderSubscriptionEvent(
            gateway: $gateway,
            type: $type,
            logicalEventKey: $logicalKey,
            normalized: $normalized,
        ));
    }

    /** @return array<string,mixed> */
    private function row(string $tenant = 'tenantA'): array
    {
        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $tenant)->first();
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function eventsFor(string $tenant = 'tenantA'): array
    {
        return $this->connection()->table('subscription_events')->where('tenant_uuid', '=', $tenant)->get();
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
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X']);

        $row = $this->row();
        self::assertSame('past_due', $row['status']);
        self::assertNotEmpty($row['grace_ends_at']);

        // grace_ends_at ~= now + grace_days (3 in the shipped config)
        $grace = new \DateTimeImmutable((string) $row['grace_ends_at']);
        $expected = new \DateTimeImmutable('+3 days');
        self::assertLessThan(120, abs($grace->getTimestamp() - $expected->getTimestamp()));

        $events = $this->eventsFor();
        self::assertCount(1, $events);
        self::assertSame('provider_event', $events[0]['source']);
        self::assertSame('paystack', $events[0]['provider_gateway']);
        self::assertSame('k1', $events[0]['provider_logical_event_key']);
        self::assertSame('trialing', $events[0]['from_status']);
        self::assertSame('past_due', $events[0]['to_status']);
    }

    public function testDuplicateEventNeverReprojectsNorExtendsGrace(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X']);

        // Plant a sentinel grace so ANY re-projection (which would recompute
        // now + grace_days) is observable -- the dup must not touch it.
        $sentinel = '2030-01-01 00:00:00';
        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', 'tenantA')
            ->update(['grace_ends_at' => $sentinel]);

        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X']);

        $row = $this->row();
        self::assertSame('past_due', $row['status']);
        self::assertSame($sentinel, $row['grace_ends_at']);
        self::assertSame(1, $this->eventCount());
    }

    public function testTransactionalClaimGatesDuplicateWhenReadSideMisses(): void
    {
        // The dedupe test above short-circuits at the read-side early-out. Here
        // existsByLogicalKey() always lies (false) -- simulating the race window
        // where two deliveries both pass the read check -- so BOTH dispatches
        // reach the transactional claim and the DB unique index is the ONLY gate:
        // claim-failure -> rollback -> no re-projection, and no exception escapes.
        $blindEvents = new class extends SubscriptionEventRepository {
            public function existsByLogicalKey(ApplicationContext $context, string $gateway, string $key): bool
            {
                return false; // the read side never sees the claim
            }
        };

        $projector = $this->projector($blindEvents);

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $project = static function () use ($projector): void {
            $projector->project(new ProviderSubscriptionEvent(
                gateway: 'paystack',
                type: 'subscription.past_due',
                logicalEventKey: 'k1',
                normalized: ['gateway_subscription_id' => 'sub_X'],
            ));
        };

        $project();

        // Sentinel grace: any re-projection would recompute now + grace_days.
        $sentinel = '2030-01-01 00:00:00';
        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', 'tenantA')
            ->update(['grace_ends_at' => $sentinel]);

        $project(); // duplicate claim must be swallowed, not thrown

        $row = $this->row();
        self::assertSame('past_due', $row['status']);
        self::assertSame($sentinel, $row['grace_ends_at']);
        self::assertSame(1, $this->eventCount()); // exactly one claim row
    }

    public function testPaymentSucceededSettlesPastDueAndClearsGrace(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'past_due',
            'grace_ends_at' => '2026-06-13 00:00:00',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('payment.succeeded', 'k2', ['gateway_subscription_id' => 'sub_X']);

        $row = $this->row();
        self::assertSame('active', $row['status']);
        self::assertNull($row['grace_ends_at']);

        $events = $this->eventsFor();
        self::assertCount(1, $events);
        self::assertSame('past_due', $events[0]['from_status']);
        self::assertSame('active', $events[0]['to_status']);
        self::assertSame('k2', $events[0]['provider_logical_event_key']);
    }

    public function testUnmappedGatewaySubscriptionIdNoOps(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k9', ['gateway_subscription_id' => 'sub_GHOST']);

        self::assertSame('active', $this->row()['status']);
        self::assertSame(0, $this->eventCount());
    }

    public function testGatewayScopedMapping(): void
    {
        // Same provider-sub id on a DIFFERENT gateway must not match (per-gateway map).
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X'], gateway: 'paystack');

        self::assertSame('active', $this->row()['status']);
        self::assertSame(0, $this->eventCount());
    }

    public function testSubscriptionCreatedRecoversLinkFromMetadataTenantUuid(): void
    {
        // Tenant exists but has never been provider-linked (e.g. started via checkout).
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ]);

        $row = $this->row();
        self::assertSame('paystack', $row['provider_gateway']);
        self::assertSame('sub_NEW', $row['provider_subscription_id']);
        self::assertSame('active', $row['status']);
        self::assertSame(1, $this->eventCount());
    }

    public function testSubscriptionCreatedDoesNotMoveAnAlreadyLinkedTenant(): void
    {
        // Tenant row is already linked to a DIFFERENT provider subscription.
        // A subscription.created naming this tenant via metadata must NOT steal
        // the link, must NOT project a status change, and must record no event.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_EXISTING',
        ]);

        $logger = new CapturingLogger();
        $this->bind('logger', $logger);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_ATTACKER',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ]);

        $row = $this->row();
        // Link is UNCHANGED -- the original provider subscription id survives.
        self::assertSame('sub_EXISTING', $row['provider_subscription_id']);
        self::assertSame('paystack', $row['provider_gateway']);
        self::assertSame('active', $row['status']);
        // No projection, no recorded event.
        self::assertSame(0, $this->eventCount());

        // The anomaly is logged as a warning without leaking the payload.
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(
            'subscriptions.relink_conflict_skipped',
            $logger->records[0]['context']['event']
        );
        self::assertSame('tenantA', $logger->records[0]['context']['tenant_uuid']);
        self::assertSame('sub_EXISTING', $logger->records[0]['context']['existing_subscription_id']);
        self::assertSame('sub_ATTACKER', $logger->records[0]['context']['incoming_subscription_id']);
    }

    public function testSubscriptionCreatedWithoutMetadataTenantUuidNoOps(): void
    {
        // Unlinked tenant row, but the created event carries no metadata hint:
        // nothing to recover -> graceful no-op, no event recorded.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
        ]);

        $row = $this->row();
        self::assertSame('incomplete', $row['status']);
        self::assertEmpty($row['provider_subscription_id'] ?? null);
        self::assertSame(0, $this->eventCount());
    }

    public function testSubscriptionCreatedForUnknownTenantNoOps(): void
    {
        // metadata names a tenant that has no subscription row -> no-op.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'ghostTenant'],
        ]);

        self::assertSame('incomplete', $this->row('tenantA')['status']);
        self::assertSame(0, $this->eventCount());
    }

    public function testLateSubscriptionCreatedDoesNotResurrectCanceledSubscription(): void
    {
        // Already-linked, terminal canceled subscription. A late/replayed
        // subscription.created (fresh logical key) must NOT flip it back to active.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'canceled',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.created', 'k_late', [
            'gateway_subscription_id' => 'sub_X',
            'status' => 'active',
        ]);

        $row = $this->row();
        // Status stays canceled -- no state regression.
        self::assertSame('canceled', $row['status']);
        // The event is still recorded as handled (claimed), just with no projection.
        $events = $this->eventsFor();
        self::assertCount(1, $events);
        self::assertSame('canceled', $events[0]['from_status']);
        self::assertSame('canceled', $events[0]['to_status']);
    }

    public function testSubscriptionCreatedActivatesNonCanceledSubscription(): void
    {
        // Fresh, non-canceled (incomplete) subscription -> normal activation.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.created', 'k_new', [
            'gateway_subscription_id' => 'sub_X',
            'status' => 'active',
        ]);

        $row = $this->row();
        self::assertSame('active', $row['status']);
        self::assertSame(1, $this->eventCount());
    }

    public function testSubscriptionCanceledProjectsCanceledAt(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k3', ['gateway_subscription_id' => 'sub_X']);

        $row = $this->row();
        self::assertSame('canceled', $row['status']);
        self::assertNotEmpty($row['canceled_at']);
    }

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
}
