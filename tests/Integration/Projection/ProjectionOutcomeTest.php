<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Projection;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Projection\ProjectionOutcome;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Projection\UnmappedProviderSubscriptionException;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 12 (design spec §4.3): the outcome-returning projection entry point
 * (`projectWithOutcome()`), the exact logical-key settled-outcome repository
 * read it shares with the duplicate/race paths, and the new deterministic
 * `origination_mismatch` rejection on the `subscription.created` activation path.
 */
final class ProjectionOutcomeTest extends SubscriptionsTestCase
{
    private const PLATFORM_PRO = 'planv2pro001';

    private function projector(?ProviderEventReceiptRepository $receipts = null): SubscriptionEventProjector
    {
        return new SubscriptionEventProjector(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            $receipts ?? new ProviderEventReceiptRepository(),
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

    /** @param array<string,mixed> $normalized */
    private function projectWithOutcome(
        string $type,
        string $logicalKey,
        array $normalized,
        string $gateway = 'paystack',
        ?SubscriptionEventProjector $projector = null,
    ): ProjectionOutcome {
        return ($projector ?? $this->projector())->projectWithOutcome(new ProviderSubscriptionEvent(
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

    /** @return array<string,mixed>|null */
    private function receiptFor(string $gateway, string $key): ?array
    {
        return $this->connection()->table('subscription_provider_event_receipts')
            ->where('provider_gateway', '=', $gateway)
            ->where('provider_logical_event_key', '=', $key)
            ->first();
    }

    // ===========================================
    // findOutcomeByLogicalKey()
    // ===========================================

    public function testFindOutcomeByLogicalKeyReturnsNullForMissingReceipt(): void
    {
        $repo = new ProviderEventReceiptRepository();

        self::assertNull($repo->findOutcomeByLogicalKey($this->appContext(), 'stripe', 'no-such-key'));
    }

    public function testFindOutcomeByLogicalKeyReturnsNullForAPendingReceipt(): void
    {
        $repo = new ProviderEventReceiptRepository();
        $repo->insertPending($this->appContext(), [
            'provider_gateway' => 'stripe',
            'provider_logical_event_key' => 'k1',
            'event_type' => 'subscription.created',
        ]);

        self::assertNull($repo->findOutcomeByLogicalKey($this->appContext(), 'stripe', 'k1'));
    }

    public function testFindOutcomeByLogicalKeyReturnsTheSettledAcceptedOutcome(): void
    {
        $repo = new ProviderEventReceiptRepository();
        $repo->insertPending($this->appContext(), [
            'uuid' => 'receipt0001a',
            'provider_gateway' => 'stripe',
            'provider_logical_event_key' => 'k1',
            'event_type' => 'subscription.created',
        ]);
        $repo->markAccepted($this->appContext(), 'receipt0001a', [
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'tenant',
            'subject_uuid' => 'tenantA',
            'plan_uuid' => self::PLATFORM_PRO,
        ]);

        $settled = $repo->findOutcomeByLogicalKey($this->appContext(), 'stripe', 'k1');

        self::assertSame(['outcome' => 'accepted', 'reason' => null, 'logical_event_key' => 'k1'], $settled);
    }

    public function testFindOutcomeByLogicalKeyReturnsTheSettledRejectedOutcomeAndReason(): void
    {
        $repo = new ProviderEventReceiptRepository();
        $repo->insertPending($this->appContext(), [
            'uuid' => 'receipt0001b',
            'provider_gateway' => 'stripe',
            'provider_logical_event_key' => 'k1',
            'event_type' => 'subscription.created',
        ]);
        $repo->markRejected($this->appContext(), 'receipt0001b', 'subject_mismatch');

        $settled = $repo->findOutcomeByLogicalKey($this->appContext(), 'stripe', 'k1');

        self::assertSame(
            ['outcome' => 'rejected', 'reason' => 'subject_mismatch', 'logical_event_key' => 'k1'],
            $settled
        );
    }

    // ===========================================
    // projectWithOutcome() -- the outcome matrix
    // ===========================================

    public function testAcceptedProjectionReturnsAcceptedOutcome(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $outcome = $this->projectWithOutcome(
            'subscription.past_due',
            'k1',
            ['gateway_subscription_id' => 'sub_X']
        );

        self::assertSame('accepted', $outcome->outcome);
        self::assertNull($outcome->reason);
        self::assertSame('k1', $outcome->logicalEventKey);
        self::assertSame('past_due', $this->row()['status']);
    }

    public function testSubjectMismatchReturnsRejectedOutcomeAndStillWritesTheReceipt(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $outcome = $this->projectWithOutcome('subscription.past_due', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'metadata' => ['tenant_uuid' => 'someOtherTenant'],
        ]);

        self::assertSame('rejected', $outcome->outcome);
        self::assertSame('subject_mismatch', $outcome->reason);
        self::assertSame('k1', $outcome->logicalEventKey);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('subject_mismatch', $receipt['rejection_code']);
    }

    public function testPlanScopeMismatchReturnsRejectedOutcome(): void
    {
        // Tenant row, unlinked, whose plan is scoped to a USER audience (never
        // assignable to a tenant subject) -- requireCoherentPlan() rejects it
        // during the 1.x tenant-metadata relink recovery.
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => 'userplan0001',
            'plan_key' => 'user_only',
            'display_name' => 'User Only',
            'entitlements' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 99,
            'audience' => 'user',
            'owner_tenant_uuid' => 'tenantA',
        ]);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'incomplete',
        ]);
        // Point the seeded row at the user-scoped plan AFTER seeding -- seedSubscription()'s
        // own plan lookup resolves plan_key against the SUBJECT's audience/owner, so a
        // tenant-subject row can't seed directly onto a user-audience plan_key.
        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', 'tenantA')
            ->update(['plan_uuid' => 'userplan0001', 'plan_key' => 'user_only']);

        $outcome = $this->projectWithOutcome('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ]);

        self::assertSame('rejected', $outcome->outcome);
        self::assertSame('plan_scope_mismatch', $outcome->reason);
    }

    public function testOriginationMismatchReturnsRejectedOutcomeAndDoesNotOverwriteTheReservation(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-NEW');

        // A late/historical webhook echoing a DIFFERENT origination_uuid than the
        // one the local row is actually reserved against.
        $outcome = $this->projectWithOutcome('subscription.created', 'k_late', [
            'gateway_subscription_id' => 'sub_STALE',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'origination_uuid' => 'origination-OLD'],
        ], gateway: 'stripe');

        self::assertSame('rejected', $outcome->outcome);
        self::assertSame('origination_mismatch', $outcome->reason);

        $row = $this->row();
        self::assertSame('incomplete', $row['status']);
        self::assertSame('origination-NEW', $row['checkout_origination_uuid']);
        self::assertEmpty($row['provider_subscription_id'] ?? null);
    }

    public function testOriginationMatchActivatesTheReservedRow(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-NEW');

        $outcome = $this->projectWithOutcome('subscription.created', 'k_new', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'origination_uuid' => 'origination-NEW'],
        ], gateway: 'stripe');

        self::assertSame('accepted', $outcome->outcome);

        $row = $this->row();
        self::assertSame('active', $row['status']);
        self::assertSame('stripe', $row['provider_gateway']);
        self::assertSame('sub_NEW', $row['provider_subscription_id']);
        self::assertSame('origination-NEW', $row['checkout_origination_uuid']);
    }

    public function testMissingEventOriginationUuidAgainstAReservedRowIsRejectedAsOriginationMismatch(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-NEW');

        // No origination_uuid at all on the event's metadata -- must not be
        // treated as an implicit match for a row that IS a reservation.
        $outcome = $this->projectWithOutcome('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ], gateway: 'stripe');

        self::assertSame('rejected', $outcome->outcome);
        self::assertSame('origination_mismatch', $outcome->reason);
        self::assertSame('incomplete', $this->row()['status']);
    }

    public function testNonReservedRowWithEventOriginationUuidIsAcceptedAsToday(): void
    {
        // Pre-2.2 / operator-created row: checkout_origination_uuid is NULL.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $outcome = $this->projectWithOutcome('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'origination_uuid' => 'origination-WHATEVER'],
        ], gateway: 'stripe');

        self::assertSame('accepted', $outcome->outcome);
        self::assertSame('active', $this->row()['status']);
    }

    public function testUnmappedStillThrowsAndWritesNoReceipt(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        try {
            $this->projectWithOutcome('subscription.past_due', 'k9', ['gateway_subscription_id' => 'sub_GHOST']);
            self::fail('Expected UnmappedProviderSubscriptionException.');
        } catch (UnmappedProviderSubscriptionException) {
            // expected -- retryable, no settled outcome to report
        }

        self::assertNull($this->receiptFor('paystack', 'k9'));
    }

    public function testTransientRepositoryFailureStillThrowsUncaught(): void
    {
        $subscriptions = new class extends SubscriptionRepository {
            public function updateBySubject(
                ApplicationContext $context,
                \Glueful\Extensions\Subscriptions\Subject $subject,
                array $changes
            ): void {
                throw new \RuntimeException('simulated transient database failure');
            }
        };

        $projector = new SubscriptionEventProjector(
            $subscriptions,
            new SubscriptionEventRepository(),
            new ProviderEventReceiptRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            new DefaultSubjectResolver(),
        );

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        try {
            $this->projectWithOutcome(
                'subscription.past_due',
                'k1',
                ['gateway_subscription_id' => 'sub_X'],
                projector: $projector,
            );
            self::fail('Expected the simulated transient failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated transient database failure', $e->getMessage());
        }

        // The whole transaction rolled back -- including the pending receipt claim.
        self::assertNull($this->receiptFor('paystack', 'k1'));
    }

    // ===========================================
    // Duplicate delivery re-reads the STORED outcome
    // ===========================================

    public function testDuplicateDeliveryOfAnAcceptedEventReReadsTheStoredAcceptedOutcome(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $first = $this->projectWithOutcome(
            'subscription.past_due',
            'k1',
            ['gateway_subscription_id' => 'sub_X']
        );
        self::assertSame('accepted', $first->outcome);

        // Read-side early-out this time (existsByLogicalKey() now sees the row).
        $second = $this->projectWithOutcome(
            'subscription.past_due',
            'k1',
            ['gateway_subscription_id' => 'sub_X']
        );

        self::assertSame('accepted', $second->outcome);
        self::assertNull($second->reason);
        self::assertSame('k1', $second->logicalEventKey);
    }

    public function testDuplicateDeliveryOfARejectedEventReReadsTheStoredRejectedOutcomeNotAGenericNoOp(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $normalized = ['gateway_subscription_id' => 'sub_X', 'metadata' => ['tenant_uuid' => 'someOtherTenant']];

        $first = $this->projectWithOutcome('subscription.past_due', 'k1', $normalized);
        self::assertSame('rejected', $first->outcome);
        self::assertSame('subject_mismatch', $first->reason);

        $second = $this->projectWithOutcome('subscription.past_due', 'k1', $normalized);

        // The exact stored REJECTED outcome/reason, never a generic accepted no-op.
        self::assertSame('rejected', $second->outcome);
        self::assertSame('subject_mismatch', $second->reason);
        self::assertSame('k1', $second->logicalEventKey);
    }

    public function testUniqueInsertRaceReReadsTheStoredOutcomeThroughTheSameMethod(): void
    {
        // existsByLogicalKey() always lies (false) -- simulating the race window
        // where two deliveries both pass the read check, so the SECOND one must
        // hit the unique-violation branch and re-read via findOutcomeByLogicalKey().
        $blindReceipts = new class extends ProviderEventReceiptRepository {
            public function existsByLogicalKey(ApplicationContext $context, string $gateway, string $key): bool
            {
                return false;
            }
        };

        $projector = $this->projector($blindReceipts);

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $normalized = ['gateway_subscription_id' => 'sub_X', 'metadata' => ['tenant_uuid' => 'someOtherTenant']];

        $first = $this->projectWithOutcome('subscription.past_due', 'k1', $normalized, projector: $projector);
        self::assertSame('rejected', $first->outcome);

        $second = $this->projectWithOutcome('subscription.past_due', 'k1', $normalized, projector: $projector);

        self::assertSame('rejected', $second->outcome);
        self::assertSame('subject_mismatch', $second->reason);
        self::assertSame('k1', $second->logicalEventKey);
    }

    // ===========================================
    // void project() still delegates
    // ===========================================

    public function testVoidProjectStillDelegatesToProjectWithOutcomeAndKeepsThrowing(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->projector()->project(new ProviderSubscriptionEvent(
            gateway: 'paystack',
            type: 'subscription.past_due',
            logicalEventKey: 'k1',
            normalized: ['gateway_subscription_id' => 'sub_X'],
        ));

        self::assertSame('past_due', $this->row()['status']);
    }

    // ===========================================
    // Receipts tie the outcome to origination_uuid via the allowlist
    // ===========================================

    public function testAcceptedReceiptDataCarriesTheOriginationUuidViaTheAllowlist(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-NEW');

        $this->projectWithOutcome('subscription.created', 'k_new', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'origination_uuid' => 'origination-NEW'],
        ], gateway: 'stripe');

        $receipt = $this->receiptFor('stripe', 'k_new');
        self::assertIsArray($receipt);
        $data = json_decode((string) $receipt['data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('origination-NEW', $data['metadata']['origination_uuid'] ?? null);
    }

    public function testRejectedReceiptDataCarriesTheOriginationUuidViaTheAllowlist(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-NEW');

        $this->projectWithOutcome('subscription.created', 'k_late', [
            'gateway_subscription_id' => 'sub_STALE',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'origination_uuid' => 'origination-OLD'],
        ], gateway: 'stripe');

        $receipt = $this->receiptFor('stripe', 'k_late');
        self::assertIsArray($receipt);
        $data = json_decode((string) $receipt['data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('origination-OLD', $data['metadata']['origination_uuid'] ?? null);
    }
}
