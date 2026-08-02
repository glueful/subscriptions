<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Projection;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\Tests\Support\PermissiveSubjectResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 10: the receipts-first provider projector. Every inbound provider event is
 * claimed as a `pending` receipt BEFORE resolution/projection, then settled to
 * accepted (atomically, with the state-machine write) or rejected (which COMMITS
 * -- rejection is not an error, it's a diagnosable outcome) inside the same
 * transaction. Only a genuine transient failure rolls the whole transaction back,
 * pending receipt included, so the provider's retry can succeed.
 */
final class ReceiptProjectionTest extends SubscriptionsTestCase
{
    private function projector(
        ?SubscriptionRepository $subscriptions = null,
        ?SubscriptionEventRepository $events = null,
        ?ProviderEventReceiptRepository $receipts = null,
        ?SubjectResolverInterface $subjects = null,
    ): SubscriptionEventProjector {
        return new SubscriptionEventProjector(
            $subscriptions ?? new SubscriptionRepository(),
            $events ?? new SubscriptionEventRepository(),
            $receipts ?? new ProviderEventReceiptRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            $subjects ?? new DefaultSubjectResolver(),
        );
    }

    /** @param array<string,mixed> $normalized */
    private function project(
        string $type,
        string $logicalKey,
        array $normalized,
        string $gateway = 'paystack',
        ?SubscriptionEventProjector $projector = null,
    ): void {
        ($projector ?? $this->projector())->project(new ProviderSubscriptionEvent(
            gateway: $gateway,
            type: $type,
            logicalEventKey: $logicalKey,
            normalized: $normalized,
        ));
    }

    /** @return array<string,mixed>|null */
    private function receiptFor(string $gateway, string $key): ?array
    {
        return $this->connection()->table('subscription_provider_event_receipts')
            ->where('provider_gateway', '=', $gateway)
            ->where('provider_logical_event_key', '=', $key)
            ->first();
    }

    private function receiptCount(): int
    {
        return count($this->connection()->table('subscription_provider_event_receipts')->get());
    }

    private function eventCount(): int
    {
        return count($this->connection()->table('subscription_events')->get());
    }

    /** @return array<string,mixed> */
    private function row(string $tenant = 'tenantA'): array
    {
        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', '=', $tenant)->first();
        self::assertIsArray($row);

        return $row;
    }

    // ===========================================
    // Claim-first + accepted-path atomicity
    // ===========================================

    public function testAcceptedEventClaimsAPendingReceiptAndSettlesItAccepted(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X']);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('accepted', $receipt['outcome']);
        self::assertNull($receipt['rejection_code']);
        self::assertSame('tenantA', $receipt['tenant_uuid']);
        self::assertSame('tenant', $receipt['subject_type']);
        self::assertSame('tenantA', $receipt['subject_uuid']);
        self::assertNotEmpty($receipt['plan_uuid']);

        // The state-machine write and the receipt settle in the SAME transaction.
        self::assertSame('past_due', $this->row()['status']);
        self::assertSame(1, $this->eventCount());
    }

    public function testReceiptDataIsSanitizedNeverTheRawPayload(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'customer_email' => 'attacker@example.com',
            'card' => ['token' => 'tok_secret'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        $data = json_decode((string) $receipt['data'], true);
        self::assertSame(['gateway_subscription_id' => 'sub_X'], $data);
    }

    public function testDuplicateLogicalKeyLosesTheReceiptsClaimAndNeverReprojects(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X']);

        $sentinel = '2030-01-01 00:00:00';
        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', 'tenantA')
            ->update(['grace_ends_at' => $sentinel]);

        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X']);

        self::assertSame($sentinel, $this->row()['grace_ends_at']);
        self::assertSame(1, $this->eventCount());
        self::assertSame(1, $this->receiptCount());
    }

    public function testTransactionalClaimGatesOnTheReceiptsUniqueWhenReadSideMisses(): void
    {
        // existsByLogicalKey() lies (false) -- simulating the race window where two
        // deliveries both pass the cheap read check -- so the DB-enforced
        // (gateway, logical_key) unique on the RECEIPTS table is the only gate.
        $blindReceipts = new class extends ProviderEventReceiptRepository {
            public function existsByLogicalKey(ApplicationContext $context, string $gateway, string $key): bool
            {
                return false;
            }
        };

        $projector = $this->projector(receipts: $blindReceipts);

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X'], projector: $projector);

        $sentinel = '2030-01-01 00:00:00';
        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', 'tenantA')
            ->update(['grace_ends_at' => $sentinel]);

        // Duplicate claim must be swallowed, not thrown.
        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X'], projector: $projector);

        self::assertSame($sentinel, $this->row()['grace_ends_at']);
        self::assertSame(1, $this->eventCount());
        self::assertSame(1, $this->receiptCount());
    }

    // ===========================================
    // subscription.created triple validation + rejection codes
    // ===========================================

    public function testMissingSubjectRejectsWhenMetadataCarriesNoTenantUuid(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('missing_subject', $receipt['rejection_code']);
        self::assertNull($receipt['tenant_uuid']);

        self::assertSame('incomplete', $this->row()['status']);
        self::assertSame(0, $this->eventCount());
    }

    public function testMissingSubjectRejectsWhenSubjectTypeGivenButSubjectUuidIsMissing(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'subject_type' => 'user'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('missing_subject', $receipt['rejection_code']);
        self::assertSame(0, $this->eventCount());
    }

    public function testMissingSubjectRejectsWhenSubjectUuidGivenButSubjectTypeIsMissing(): void
    {
        // The partial-pair rule cuts BOTH ways: subject_uuid present without
        // subject_type must never silently default to a tenant self-subject --
        // that would coerce a member's identity into the workspace's, and the
        // workspace row would relink to the member's provider subscription.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'subject_uuid' => 'user_1'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('missing_subject', $receipt['rejection_code']);

        $row = $this->row();
        self::assertEmpty($row['provider_subscription_id'] ?? null); // NOT relinked
        self::assertSame('incomplete', $row['status']);
        self::assertSame(0, $this->eventCount());
    }

    public function testInvalidSubjectRejectsAUserSubjectUnderTheDefaultResolver(): void
    {
        // DefaultSubjectResolver rejects every user subject (spec §4) -- a webhook
        // naming one, even a well-formed one, fails validate() and is rejected.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'subject_type' => 'user', 'subject_uuid' => 'user_1'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('invalid_subject', $receipt['rejection_code']);
        self::assertSame(0, $this->eventCount());
    }

    public function testUnmappedSubscriptionRejectsAValidatedNonTenantSubjectInsteadOfRelinking(): void
    {
        // The 1.x tenant-metadata relink recovery survives ONLY for validated
        // TENANT subjects -- a validated USER subject must NOT trigger it.
        $projector = $this->projector(subjects: new PermissiveSubjectResolver());

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'subject_type' => 'user', 'subject_uuid' => 'user_1'],
        ], projector: $projector);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('unmapped_subscription', $receipt['rejection_code']);
        self::assertSame(0, $this->eventCount());
        self::assertSame('incomplete', $this->row()['status']);
    }

    public function testUnmappedSubscriptionRejectsWhenTheNamedTenantHasNoSubscriptionRow(): void
    {
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

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('unmapped_subscription', $receipt['rejection_code']);
        self::assertSame(0, $this->eventCount());
    }

    public function testPlanScopeMismatchRejectsWhenTheTargetRowsPlanDoesNotResolve(): void
    {
        // Unlinked tenant row whose plan_uuid points at nothing resolvable -- the
        // relink is refused before any state is touched, distinct from the
        // subject-identity codes above.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
            'plan_uuid' => 'ghostplan001',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('plan_scope_mismatch', $receipt['rejection_code']);
        self::assertSame(0, $this->eventCount());
        self::assertEmpty($this->row()['provider_subscription_id'] ?? null);
    }

    public function testPlanScopeMismatchRejectsWhenTheTargetRowsPlanIsScopedToAnotherAudience(): void
    {
        $userPlanUuid = 'userplan0001';
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => $userPlanUuid,
            'plan_key' => 'member',
            'display_name' => 'Member',
            'entitlements' => json_encode([]),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => 'someOtherTenant',
        ]);

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
            'plan_uuid' => $userPlanUuid,
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('plan_scope_mismatch', $receipt['rejection_code']);
        self::assertSame(0, $this->eventCount());
        self::assertEmpty($this->row()['provider_subscription_id'] ?? null);
    }

    public function testValidatedTenantRelinkStillWorksAndAcceptsTheReceipt(): void
    {
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

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('accepted', $receipt['outcome']);
        self::assertSame('tenantA', $receipt['tenant_uuid']);
        self::assertSame('tenant', $receipt['subject_type']);
        self::assertSame('tenantA', $receipt['subject_uuid']);

        $row = $this->row();
        self::assertSame('sub_NEW', $row['provider_subscription_id']);
        self::assertSame('active', $row['status']);
        self::assertSame(1, $this->eventCount());
    }

    public function testRelinkConflictStillRefusesToMoveAnExistingLinkAndRejectsTheReceipt(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_EXISTING',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_ATTACKER',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('unmapped_subscription', $receipt['rejection_code']);

        $row = $this->row();
        self::assertSame('sub_EXISTING', $row['provider_subscription_id']);
        self::assertSame(0, $this->eventCount());
    }

    // ===========================================
    // Later-event subject cross-check
    // ===========================================

    public function testSubjectMismatchRejectsALaterEventWhoseMetadataDisagreesWithTheStoredTriple(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'metadata' => ['tenant_uuid' => 'tenantB'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('subject_mismatch', $receipt['rejection_code']);
        // The raw (unresolved) candidate identity from metadata is preserved on
        // the receipt even though it was never trusted -- diagnosability of a
        // rejection depends on it.
        self::assertSame('tenantB', $receipt['candidate_tenant_uuid']);
        self::assertNull($receipt['tenant_uuid']); // never resolved -- rejected

        $row = $this->row();
        self::assertSame('trialing', $row['status']);
        self::assertNull($row['grace_ends_at']);
        self::assertSame(0, $this->eventCount());
    }

    public function testAgreeingSubjectMetadataOnALaterEventStillAccepts(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'metadata' => ['tenant_uuid' => 'tenantA', 'subject_type' => 'tenant', 'subject_uuid' => 'tenantA'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('accepted', $receipt['outcome']);
        self::assertSame('past_due', $this->row()['status']);
        self::assertSame(1, $this->eventCount());
    }

    public function testEmptyStringSubjectTypeMetadataOnALaterEventIsTreatedAsAbsentNotMismatch(): void
    {
        // A provider that echoes back an unset metadata field as '' (rather than
        // omitting the key) must NOT be read as an explicit empty claim -- that
        // would mismatch against any non-empty stored subject_type and reject
        // the receipt. Because claims are permanent (the logical key is spent),
        // every SUBSEQUENT event for this subscription would then also reject,
        // silently freezing billing state behind 200 OK responses.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k1', [
            'gateway_subscription_id' => 'sub_X',
            'metadata' => ['subject_type' => '', 'subject_uuid' => null],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('accepted', $receipt['outcome']);
        self::assertNotSame('subject_mismatch', $receipt['rejection_code']);

        $row = $this->row();
        self::assertSame('past_due', $row['status']);
        self::assertSame(1, $this->eventCount());
    }

    public function testCreatedResendOnALinkedRowWithMismatchingMetadataRejectsSubjectMismatch(): void
    {
        // A subscription.created delivered for a row ALREADY linked by
        // (gateway, provider_subscription_id) is a resend, not a new-link
        // attempt -- it goes through the same later-event cross-check as any
        // other type, not the metadata-driven recovery path.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.created', 'k_resend', [
            'gateway_subscription_id' => 'sub_X',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantB'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k_resend');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('subject_mismatch', $receipt['rejection_code']);

        $row = $this->row();
        self::assertSame('active', $row['status']);
        self::assertSame('sub_X', $row['provider_subscription_id']);
        self::assertSame(0, $this->eventCount());
    }

    public function testUnmappedGatewaySubscriptionIdRejectsWithoutTouchingState(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project('subscription.past_due', 'k9', ['gateway_subscription_id' => 'sub_GHOST']);

        $receipt = $this->receiptFor('paystack', 'k9');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('unmapped_subscription', $receipt['rejection_code']);
        self::assertSame('active', $this->row()['status']);
        self::assertSame(0, $this->eventCount());
    }

    // ===========================================
    // Transient failure: whole transaction rolls back
    // ===========================================

    public function testTransientFailureDuringUpdateRollsBackTheWholeTransactionIncludingThePendingReceipt(): void
    {
        $failing = new class extends SubscriptionRepository {
            public function updateBySubject(ApplicationContext $context, Subject $subject, array $changes): void
            {
                throw new \RuntimeException('transient database failure');
            }
        };

        $projector = $this->projector(subscriptions: $failing);

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        try {
            $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X'], projector: $projector);
            self::fail('Expected the transient failure to propagate out of project().');
        } catch (\RuntimeException $e) {
            self::assertSame('transient database failure', $e->getMessage());
        }

        // The whole transaction rolled back: the pending receipt vanished (so a
        // provider retry of the SAME logical key can succeed later), no event was
        // recorded, and the subscription row is untouched.
        self::assertNull($this->receiptFor('paystack', 'k1'));
        self::assertSame(0, $this->receiptCount());
        self::assertSame(0, $this->eventCount());
        self::assertSame('trialing', $this->row()['status']);
    }

    public function testRetryAfterATransientFailureSucceeds(): void
    {
        $failing = new class extends SubscriptionRepository {
            private int $calls = 0;

            public function updateBySubject(ApplicationContext $context, Subject $subject, array $changes): void
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('transient database failure');
                }
                parent::updateBySubject($context, $subject, $changes);
            }
        };

        $projector = $this->projector(subscriptions: $failing);

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        try {
            $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X'], projector: $projector);
            self::fail('Expected the first attempt to throw.');
        } catch (\RuntimeException) {
            // expected
        }

        // Provider retries the SAME logical key -- the vanished pending receipt
        // means the claim is free again.
        $this->project('subscription.past_due', 'k1', ['gateway_subscription_id' => 'sub_X'], projector: $projector);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('accepted', $receipt['outcome']);
        self::assertSame('past_due', $this->row()['status']);
        self::assertSame(1, $this->eventCount());
        self::assertSame(1, $this->receiptCount());
    }
}
