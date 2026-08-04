<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\CheckoutReservationException;
use Glueful\Extensions\Subscriptions\Database\Migrations\CheckoutReservations;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 10 (design spec §4.1): the origination-bound checkout reservation seam
 * (`reserveCheckoutFor`/`releaseCheckoutReservation`) plus migration 007.
 *
 * `reserveCheckoutFor()` always attempts an optimistic insert first (mirroring
 * `startFor()`'s own shape -- see its docblock), so every "a row already exists"
 * scenario below (idempotent replay, ad-hoc replace, flagged replace, the
 * already_subscribed refusal, a genuinely concurrent race) is exercised through
 * ordinary sequential calls: the SECOND call's insert always loses the real
 * `uniq_subscriptions_subject` unique constraint, driving it through the exact
 * same lost-race resolution a true concurrent writer would hit.
 */
final class CheckoutReservationTest extends SubscriptionsTestCase
{
    private const PLATFORM_FREE = 'planv2free01';
    private const PLATFORM_PRO = 'planv2pro001';

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

    /** @return list<array<string,mixed>> */
    private function events(Subject $subject): array
    {
        return $this->connection()->table('subscription_events')
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid)
            ->get();
    }

    private function row(Subject $subject): ?array
    {
        return $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid)
            ->first();
    }

    // ---------------------------------------------------------------
    // Fresh reservation
    // ---------------------------------------------------------------

    public function testReservesANonEntitlingIncompleteRowWithNoProviderFields(): void
    {
        $subject = Subject::tenant('tenantA');

        $row = $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination01', [
            'actor' => 'user-1',
        ]);

        self::assertSame('incomplete', $row['status']);
        self::assertSame(self::PLATFORM_PRO, $row['plan_uuid']);
        self::assertSame('pro', $row['plan_key']);
        self::assertSame('origination01', $row['checkout_origination_uuid']);
        self::assertNull($row['provider_gateway']);
        self::assertNull($row['provider_customer_id']);
        self::assertNull($row['provider_subscription_id']);
        self::assertNull($row['provider_price_id']);

        $events = $this->events($subject);
        self::assertCount(1, $events);
        self::assertSame('checkout_reserved', $events[0]['type']);
        self::assertSame('checkout_reservation', $events[0]['source']);
        self::assertNull($events[0]['from_status']);
        self::assertSame('incomplete', $events[0]['to_status']);

        $data = json_decode((string) $events[0]['data'], true);
        self::assertSame('origination01', $data['origination_uuid']);
        self::assertSame('pro', $data['plan_key']);
        self::assertSame('user-1', $data['actor']);
    }

    public function testActorIsOmittedFromTheEventWhenNotProvided(): void
    {
        $subject = Subject::tenant('tenantA');

        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');

        $data = json_decode((string) $this->events($subject)[0]['data'], true);
        self::assertArrayNotHasKey('actor', $data);
    }

    public function testRejectsAnEmptyOriginationUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->reserveCheckoutFor(Subject::tenant('tenantA'), self::PLATFORM_FREE, '  ');
    }

    public function testRejectsASubjectTheResolverRefuses(): void
    {
        $service = new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            new DefaultSubjectResolver(), // rejects ALL user subjects
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid subject');

        $service->reserveCheckoutFor(Subject::user('tenantA', 'user-1'), self::PLATFORM_FREE, 'origination01');
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function testSameOriginationAndPlanReturnsTheReservationUnchanged(): void
    {
        $subject = Subject::tenant('tenantA');
        $service = $this->service();

        $first = $service->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination01');
        $second = $service->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination01');

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame('origination01', $second['checkout_origination_uuid']);
        self::assertSame(1, $this->connection()->table('subscriptions')->count());
        // No duplicate event on the idempotent replay.
        self::assertCount(1, $this->events($subject));
    }

    // ---------------------------------------------------------------
    // Replace-guard matrix
    // ---------------------------------------------------------------

    public function testAdHocReplacementWithADifferentOriginationIsRefused(): void
    {
        $subject = Subject::tenant('tenantA');
        $service = $this->service();

        $service->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-A');

        try {
            $service->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-B');
            self::fail('Expected the ad-hoc replacement to be refused.');
        } catch (CheckoutReservationException $e) {
            self::assertSame('checkout_reservation_replace_refused', $e->reasonCode);
        }

        $row = $this->row($subject);
        self::assertSame('origination-A', $row['checkout_origination_uuid']);
        self::assertCount(1, $this->events($subject));
    }

    public function testAdHocReplacementWithADifferentPlanIsRefused(): void
    {
        $subject = Subject::tenant('tenantA');
        $service = $this->service();

        $service->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination-A');

        try {
            $service->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-A');
            self::fail('Expected the ad-hoc replacement to be refused.');
        } catch (CheckoutReservationException $e) {
            self::assertSame('checkout_reservation_replace_refused', $e->reasonCode);
        }

        $row = $this->row($subject);
        self::assertSame(self::PLATFORM_FREE, $row['plan_uuid']);
    }

    public function testFlaggedReplacementReplacesTheIncompleteReservation(): void
    {
        $subject = Subject::tenant('tenantA');
        $service = $this->service();

        $service->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination-A');
        $row = $service->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-B', ['replace' => true]);

        self::assertSame(self::PLATFORM_PRO, $row['plan_uuid']);
        self::assertSame('origination-B', $row['checkout_origination_uuid']);
        self::assertSame(1, $this->connection()->table('subscriptions')->count());

        $events = $this->events($subject);
        self::assertCount(2, $events);
        self::assertSame('incomplete', $events[1]['from_status']);
        self::assertSame('incomplete', $events[1]['to_status']);
    }

    public function testTwoPlanRaceTheSecondReservationWithoutTheFlagLoses(): void
    {
        // Two concurrent checkout attempts for the SAME subject on DIFFERENT plans.
        // Sequentially, the first call wins the row; the second's insert loses the
        // real unique constraint and must resolve through the exact same
        // ad-hoc-refusal path -- it can never silently steal the reservation.
        $subject = Subject::tenant('tenantA');
        $service = $this->service();

        $winner = $service->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination-A');

        try {
            $service->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-B');
            self::fail('Expected the losing plan request to be refused.');
        } catch (CheckoutReservationException $e) {
            self::assertSame('checkout_reservation_replace_refused', $e->reasonCode);
        }

        $row = $this->row($subject);
        self::assertSame($winner['plan_uuid'], $row['plan_uuid']);
        self::assertSame('origination-A', $row['checkout_origination_uuid']);
    }

    // ---------------------------------------------------------------
    // already_subscribed refusal matrix
    // ---------------------------------------------------------------

    /** @return iterable<string,array{string}> */
    public static function entitlingStatuses(): iterable
    {
        yield 'active' => ['active'];
        yield 'trialing' => ['trialing'];
        yield 'past_due' => ['past_due'];
    }

    /** @dataProvider entitlingStatuses */
    public function testRefusesAlreadySubscribedForEntitlingStatuses(string $status): void
    {
        $subject = Subject::tenant('tenantA');
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => $status]);

        try {
            $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');
            self::fail("Expected status '{$status}' to refuse the reservation.");
        } catch (CheckoutReservationException $e) {
            self::assertSame('already_subscribed', $e->reasonCode);
        }

        self::assertSame($status, $this->row($subject)['status']);
    }

    public function testRefusesAlreadySubscribedForUnexpiredNonRenewing(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'non_renewing',
            'current_period_end' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ]);

        try {
            $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');
            self::fail('Expected an unexpired non_renewing subscription to refuse the reservation.');
        } catch (CheckoutReservationException $e) {
            self::assertSame('already_subscribed', $e->reasonCode);
        }
    }

    public function testExpiredNonRenewingIsReplaceableByANewReservation(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'non_renewing',
            'current_period_end' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ]);

        $row = $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');

        self::assertSame('incomplete', $row['status']);
        self::assertSame(self::PLATFORM_FREE, $row['plan_uuid']);
        self::assertSame('origination01', $row['checkout_origination_uuid']);
        self::assertNull($row['current_period_end']);
    }

    public function testNonRenewingWithNoPeriodEndIsTreatedAsExpiredAndReplaceable(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'non_renewing',
            'current_period_end' => null,
        ]);

        $row = $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');

        self::assertSame('incomplete', $row['status']);
    }

    /** @return iterable<string,array{string}> */
    public static function nonEntitlingReplaceableStatuses(): iterable
    {
        yield 'canceled' => ['canceled'];
        yield 'paused' => ['paused'];
        yield 'an unknown status string' => ['bogus'];
    }

    /** @dataProvider nonEntitlingReplaceableStatuses */
    public function testNonEntitlingStatusesAreReplaceableByANewReservation(string $status): void
    {
        $subject = Subject::tenant('tenantA');
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => $status]);

        $row = $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');

        self::assertSame('incomplete', $row['status']);
        self::assertSame(self::PLATFORM_FREE, $row['plan_uuid']);
    }

    // ---------------------------------------------------------------
    // Zero-entitlement proof
    // ---------------------------------------------------------------

    public function testIncompleteReservationGrantsNoEntitlementsBeyondTheDefaultPlan(): void
    {
        $subject = Subject::tenant('tenantA');
        $resolver = $this->entitlementResolver();

        // A ghost tenant with NO subscription row at all -- the baseline "grants
        // nothing but the default plan" outcome.
        $noSubscription = $resolver->resolveMap($this->appContext(), 'ghost-tenant');

        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination01');
        $reserved = $resolver->resolveMap($this->appContext(), 'tenantA');

        // Reserving against the PAID 'pro' plan must not grant ANY of its
        // entitlements -- the resolved map is byte-identical to a tenant with no
        // subscription row at all, and NOT the 'pro' catalog's map (sanity: the
        // default plan's map is non-empty, so this isn't trivially true).
        self::assertSame($noSubscription, $reserved);
        self::assertNotSame([], $reserved);
        self::assertSame(false, $reserved['reports.export'] ?? null);
    }

    // ---------------------------------------------------------------
    // Release CAS matrix
    // ---------------------------------------------------------------

    public function testReleaseDeletesAMatchingIncompleteReservationAndReturnsTrue(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');

        $released = $this->service()->releaseCheckoutReservation($subject, 'origination01');

        self::assertTrue($released);
        self::assertNull($this->row($subject));
    }

    public function testReleaseReturnsFalseForAMismatchedOrigination(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');

        $released = $this->service()->releaseCheckoutReservation($subject, 'origination-WRONG');

        self::assertFalse($released);
        self::assertNotNull($this->row($subject));
        self::assertSame('origination01', $this->row($subject)['checkout_origination_uuid']);
    }

    public function testReleaseReturnsFalseAndLeavesTheRowIntactWhenProviderFieldsArePresent(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');
        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', 'tenantA')
            ->update(['provider_gateway' => 'stripe', 'provider_subscription_id' => 'sub_1']);

        $released = $this->service()->releaseCheckoutReservation($subject, 'origination01');

        self::assertFalse($released);
        self::assertNotNull($this->row($subject));
    }

    /**
     * Minor fix: `reservationChanges()` clears FOUR provider fields, not three --
     * the release guard must refuse on `provider_price_id` alone too, even with
     * no gateway/customer/subscription id written (a plan-only linkage some
     * provider integration paths may write before the rest).
     */
    public function testReleaseReturnsFalseWhenOnlyProviderPriceIdIsPresent(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_FREE, 'origination01');
        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', 'tenantA')
            ->update(['provider_price_id' => 'price_123']);

        $released = $this->service()->releaseCheckoutReservation($subject, 'origination01');

        self::assertFalse($released);
        self::assertNotNull($this->row($subject));
    }

    public function testReleaseReturnsFalseForAnAlreadyEntitledRow(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);

        $released = $this->service()->releaseCheckoutReservation($subject, 'origination01');

        self::assertFalse($released);
        self::assertNotNull($this->row($subject));
        self::assertSame('active', $this->row($subject)['status']);
    }

    public function testReleaseReturnsFalseWhenNoRowExistsAtAll(): void
    {
        $subject = Subject::tenant('tenantA');

        $released = $this->service()->releaseCheckoutReservation($subject, 'origination01');

        self::assertFalse($released);
        self::assertNull($this->row($subject));
    }

    // ---------------------------------------------------------------
    // Migration 007 reversibility
    // ---------------------------------------------------------------

    public function testMigrationDownRemovesTheColumnAndUpIsReRunnable(): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        self::assertTrue($schema->hasColumn('subscriptions', 'checkout_origination_uuid'));

        (new CheckoutReservations())->down($schema);
        self::assertFalse($schema->hasColumn('subscriptions', 'checkout_origination_uuid'));

        (new CheckoutReservations())->up($schema);
        self::assertTrue($schema->hasColumn('subscriptions', 'checkout_origination_uuid'));

        // Still usable after the round trip.
        $row = $this->service()->reserveCheckoutFor(Subject::tenant('tenantA'), self::PLATFORM_FREE, 'origination01');
        self::assertSame('incomplete', $row['status']);
    }
}
