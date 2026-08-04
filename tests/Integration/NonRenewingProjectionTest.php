<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\CheckoutReservationException;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 11 (design spec §3.7/§4.3): Paystack's `stop_renewal` disable stops
 * future charges but the already-paid period runs to its end -- the projector
 * records that as `non_renewing`, KEEPING `current_period_end`, never an
 * immediate `canceled`. Immediate cancellation (no mode, or an explicit
 * 'immediate' mode, or a stop_renewal event missing a parseable period end)
 * fails closed to today's `canceled` exactly as before -- a `non_renewing` row
 * without a boundary to resolve against must never exist.
 *
 * This suite drives the projector through its real `ProviderSubscriptionEvent`
 * entry point (never seeds `non_renewing` directly -- CheckoutReservationTest
 * already covers the reservation guard against a directly-seeded row) and then
 * asserts EFFECTIVE outcomes end-to-end: the projected row shape, and both
 * `reserveCheckoutFor()`'s refusal and `EntitlementResolver`'s plan resolution
 * immediately before/after the real boundary.
 */
final class NonRenewingProjectionTest extends SubscriptionsTestCase
{
    private const PLATFORM_FREE = 'planv2free01';

    private function projector(): SubscriptionEventProjector
    {
        return new SubscriptionEventProjector(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            new ProviderEventReceiptRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            new DefaultSubjectResolver(),
        );
    }

    private function service(): SubscriptionService
    {
        return new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            new DefaultSubjectResolver(),
        );
    }

    private function entitlementResolver(): EntitlementResolver
    {
        return new EntitlementResolver(
            PlanCatalog::fromContext($this->appContext()),
            new SubscriptionRepository(),
            new OverrideRepository(),
            new EffectivePlanResolver(),
            null,
            false,
        );
    }

    /** @param array<string,mixed> $normalized */
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

    // ===========================================
    // computeChanges() mapping
    // ===========================================

    public function testStopRenewalWithPeriodEndProjectsNonRenewingKeepingPeriodEnd(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'cancellation_mode' => 'stop_renewal',
            'current_period_end' => '2026-07-01 00:00:00',
        ]);

        $row = $this->row();
        self::assertSame('non_renewing', $row['status']);
        self::assertSame('2026-07-01 00:00:00', $row['current_period_end']);
        // The disable moment is still recorded -- it is a timestamp of the
        // disable action, not of eventual entitlement loss.
        self::assertNotEmpty($row['canceled_at']);
    }

    public function testImmediateCancelWithNoModeProjectsCanceled(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k1', ['gateway_subscription_id' => 'sub_X']);

        $row = $this->row();
        self::assertSame('canceled', $row['status']);
        self::assertNotEmpty($row['canceled_at']);
    }

    public function testExplicitImmediateModeProjectsCanceledEvenWithPeriodEnd(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'cancellation_mode' => 'immediate',
            'current_period_end' => '2026-07-01 00:00:00',
        ], gateway: 'stripe');

        self::assertSame('canceled', $this->row()['status']);
    }

    public function testStopRenewalWithMissingPeriodEndFailsClosedToCanceled(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'cancellation_mode' => 'stop_renewal',
        ]);

        self::assertSame('canceled', $this->row()['status']);
    }

    public function testStopRenewalWithInvalidPeriodEndFailsClosedToCanceled(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'cancellation_mode' => 'stop_renewal',
            'current_period_end' => 'not-a-date',
        ]);

        self::assertSame('canceled', $this->row()['status']);
    }

    /**
     * CRITICAL regression (code review): a terminally `canceled` row must
     * NEVER be resurrected into an entitling status by a later, distinct
     * logical-key event -- mirrors `subscription.created`'s own
     * never-resurrect guard a few cases above. Replay scenario: the row was
     * already canceled immediately; a late/out-of-order/reconciliation
     * `subscription.canceled` naming `cancellation_mode=stop_renewal` plus a
     * FUTURE period end must not flip it back to entitling `non_renewing`.
     */
    public function testAlreadyCanceledRowIsNeverResurrectedIntoNonRenewing(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'canceled',
            'canceled_at' => '2026-01-01 00:00:00',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k_late', [
            'gateway_subscription_id' => 'sub_X',
            'cancellation_mode' => 'stop_renewal',
            'current_period_end' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $row = $this->row();
        self::assertSame('canceled', $row['status']);
        self::assertSame('2026-01-01 00:00:00', $row['canceled_at']); // untouched
        self::assertFalse($this->entitlementResolver()->resolveMap($this->appContext(), 'tenantA')['reports.export']);

        // The event is still claimed/recorded (like the created-case guard), just with no projection.
        $events = $this->connection()->table('subscription_events')->where('tenant_uuid', '=', 'tenantA')->get();
        self::assertCount(1, $events);
        self::assertSame('canceled', $events[0]['from_status']);
        self::assertSame('canceled', $events[0]['to_status']);
    }

    /**
     * MINOR 2 (code review): a redelivered/late cancellation event on a row
     * that is ALREADY `non_renewing` (not yet terminally canceled) must not
     * re-stamp `canceled_at` -- it documents the original disable MOMENT, and
     * restamping it on every redelivery would drift that audit timestamp.
     */
    public function testRedeliveredStopRenewalPreservesTheOriginalCanceledAt(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'non_renewing',
            'canceled_at' => '2026-01-01 00:00:00',
            'current_period_end' => '2026-07-01 00:00:00',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k_redelivery', [
            'gateway_subscription_id' => 'sub_X',
            'cancellation_mode' => 'stop_renewal',
            'current_period_end' => '2026-08-01 00:00:00', // provider re-sent a refreshed boundary
        ]);

        $row = $this->row();
        self::assertSame('non_renewing', $row['status']);
        self::assertSame('2026-08-01 00:00:00', $row['current_period_end']); // still tracks the provider
        self::assertSame('2026-01-01 00:00:00', $row['canceled_at']); // original disable moment preserved
    }

    // ===========================================
    // Effective entitlement + reservation, before/after the real boundary
    // ===========================================

    public function testEntitlementIsGrantedBeforeAndLostAfterTheProjectedBoundary(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'cancellation_mode' => 'stop_renewal',
            'current_period_end' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $row = $this->row();
        self::assertSame('non_renewing', $row['status']);

        $map = $this->entitlementResolver()->resolveMap($this->appContext(), 'tenantA');
        self::assertTrue($map['reports.export']); // still the pro plan -- boundary not reached yet
    }

    public function testEntitlementFallsBackToDefaultOnceTheProjectedBoundaryHasPassed(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'cancellation_mode' => 'stop_renewal',
            'current_period_end' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ]);

        self::assertSame('non_renewing', $this->row()['status']);

        $map = $this->entitlementResolver()->resolveMap($this->appContext(), 'tenantA');
        self::assertFalse($map['reports.export']); // default plan -- boundary already passed
    }

    public function testReserveCheckoutForRefusesBeforeTheProjectedBoundaryAndAllowsAfter(): void
    {
        // Refusal case: project a stop_renewal cancel whose boundary is still
        // ahead of us -- reserveCheckoutFor() must refuse using the REAL
        // projected status/current_period_end, not a directly-seeded fixture.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.canceled', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'cancellation_mode' => 'stop_renewal',
            'current_period_end' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $subject = Subject::tenant('tenantA');

        try {
            $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');
            self::fail('Expected an unexpired, projected non_renewing subscription to refuse the reservation.');
        } catch (CheckoutReservationException $e) {
            self::assertSame('already_subscribed', $e->reasonCode);
        }

        // Allowed case: an independent tenant whose stop_renewal boundary has
        // already passed -- the projected row is expired and replaceable.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantB',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_Y',
        ]);

        $this->project('subscription.canceled', 'k2', [
            'gateway_subscription_id' => 'sub_Y',
            'cancellation_mode' => 'stop_renewal',
            'current_period_end' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ]);

        $row = $this->service()->reserveCheckoutFor(Subject::tenant('tenantB'), self::PLATFORM_FREE, 'origination02');
        self::assertSame('incomplete', $row['status']);
    }
}
