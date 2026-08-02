<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Projection;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Projection\UnmappedProviderSubscriptionException;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\Tests\Support\PermissiveSubjectResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 10: the receipts-first provider projector. Every inbound provider event is
 * claimed as a `pending` receipt BEFORE resolution/projection, then settled
 * ATOMICALLY, split by determinism (fix round 2):
 * - Deterministic rejections (missing_subject, invalid_subject,
 *   plan_scope_mismatch, subject_mismatch) settle the receipt `rejected` and
 *   COMMIT -- a rejection is a diagnosable outcome, not an error.
 * - "Unmapped" is retryable, not deterministic: it throws
 *   {@see UnmappedProviderSubscriptionException} and rolls the WHOLE
 *   transaction back, pending receipt claim included.
 * - Any other genuine transient failure also rolls the whole transaction back,
 *   pending receipt included, so the provider's retry can succeed.
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

    /** @return array<string,mixed>|null */
    private function eventRowFor(string $gateway, string $key): ?array
    {
        return $this->connection()->table('subscription_events')
            ->where('provider_gateway', '=', $gateway)
            ->where('provider_logical_event_key', '=', $key)
            ->first();
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

    /**
     * RULING B: ProviderEventData::sanitize() is shared by BOTH writers -- the
     * receipt's `data` AND the accepted subscription_events row's `data` must
     * end up with the IDENTICAL safe projection of the same hostile payload;
     * neither ever stores the raw fields.
     */
    public function testAcceptedEventDataIsSanitizedIdenticallyOnBothReceiptAndEvent(): void
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
            'status' => 'active',
            'customer_email' => 'attacker@example.com',
            'card' => ['token' => 'tok_secret'],
            'metadata' => [
                'tenant_uuid' => 'tenantA',
                'billing_email' => 'someone@example.com',
                'api_key' => 'sk_live_hostile',
            ],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('accepted', $receipt['outcome']);
        $receiptData = json_decode((string) $receipt['data'], true);

        $event = $this->eventRowFor('paystack', 'k1');
        self::assertIsArray($event);
        $eventData = json_decode((string) $event['data'], true);

        // Same safe projection on both sides -- and neither leaks the hostile fields.
        self::assertSame($receiptData, $eventData);
        self::assertArrayNotHasKey('customer_email', $eventData);
        self::assertArrayNotHasKey('card', $eventData);
        self::assertArrayNotHasKey('billing_email', $eventData['metadata'] ?? []);
        self::assertArrayNotHasKey('api_key', $eventData['metadata'] ?? []);
        self::assertSame('tenantA', $eventData['metadata']['tenant_uuid'] ?? null);
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

    public function testUnmappedSubscriptionThrowsRetryableForAValidatedNonTenantSubjectInsteadOfRelinking(): void
    {
        // The 1.x tenant-metadata relink recovery survives ONLY for validated
        // TENANT subjects -- a validated USER subject must NOT trigger it, and
        // (fix round 2) "unmapped" is now a RETRYABLE rollback, not a committed
        // rejection: the deterministic codes (missing/invalid_subject,
        // plan_scope_mismatch, subject_mismatch) stay committed, but "unmapped"
        // alone rolls back the pending receipt claim too.
        $projector = $this->projector(subjects: new PermissiveSubjectResolver());

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        try {
            $this->project('subscription.created', 'k1', [
                'gateway_subscription_id' => 'sub_NEW',
                'status' => 'active',
                'metadata' => ['tenant_uuid' => 'tenantA', 'subject_type' => 'user', 'subject_uuid' => 'user_1'],
            ], projector: $projector);
            self::fail('Expected UnmappedProviderSubscriptionException.');
        } catch (UnmappedProviderSubscriptionException) {
            // expected -- retryable
        }

        self::assertNull($this->receiptFor('paystack', 'k1'));
        self::assertSame(0, $this->receiptCount());
        self::assertSame(0, $this->eventCount());
        self::assertSame('incomplete', $this->row()['status']);
    }

    public function testUnmappedSubscriptionThrowsRetryableWhenTheNamedTenantHasNoSubscriptionRow(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        try {
            $this->project('subscription.created', 'k1', [
                'gateway_subscription_id' => 'sub_NEW',
                'status' => 'active',
                'metadata' => ['tenant_uuid' => 'ghostTenant'],
            ]);
            self::fail('Expected UnmappedProviderSubscriptionException.');
        } catch (UnmappedProviderSubscriptionException) {
            // expected -- retryable
        }

        self::assertNull($this->receiptFor('paystack', 'k1'));
        self::assertSame(0, $this->receiptCount());
        self::assertSame(0, $this->eventCount());
    }

    /**
     * RULING A's required test: a first delivery that cannot map to anything
     * throws the typed retryable exception, rolling back the claim entirely (no
     * receipt row, no event, no state change) -- then, once the local
     * subscription is created, redelivering the EXACT SAME logical key succeeds
     * (the claim was never spent by the first, failed attempt).
     */
    public function testUnmappedSubscriptionCanBeRetriedOnceTheLocalSubscriptionExists(): void
    {
        $projector = $this->projector();

        try {
            $this->project(
                'subscription.past_due',
                'k1',
                ['gateway_subscription_id' => 'sub_X'],
                projector: $projector
            );
            self::fail('Expected UnmappedProviderSubscriptionException on the first delivery.');
        } catch (UnmappedProviderSubscriptionException) {
            // expected -- retryable
        }

        // Nothing durable was left behind by the failed first attempt.
        self::assertNull($this->receiptFor('paystack', 'k1'));
        self::assertSame(0, $this->receiptCount());
        self::assertSame(0, $this->eventCount());
        self::assertNull(
            $this->connection()->table('subscriptions')->where('tenant_uuid', '=', 'tenantA')->first()
        );

        // The local subscription now comes into existence (e.g. via checkout).
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        // The provider redelivers the SAME logical event key -- it succeeds now.
        $this->project(
            'subscription.past_due',
            'k1',
            ['gateway_subscription_id' => 'sub_X'],
            projector: $projector
        );

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('accepted', $receipt['outcome']);
        self::assertSame(1, $this->receiptCount());
        self::assertSame(1, $this->eventCount());

        $row = $this->row();
        self::assertSame('past_due', $row['status']);
        self::assertNotEmpty($row['grace_ends_at']);
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

    public function testRelinkConflictStillRefusesToMoveAnExistingLinkAndThrowsRetryable(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_EXISTING',
        ]);

        try {
            $this->project('subscription.created', 'k1', [
                'gateway_subscription_id' => 'sub_ATTACKER',
                'status' => 'active',
                'metadata' => ['tenant_uuid' => 'tenantA'],
            ]);
            self::fail('Expected UnmappedProviderSubscriptionException.');
        } catch (UnmappedProviderSubscriptionException) {
            // expected -- retryable, but the link is still never moved (below).
        }

        self::assertNull($this->receiptFor('paystack', 'k1'));
        self::assertSame(0, $this->receiptCount());

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

    public function testUnmappedGatewaySubscriptionIdThrowsRetryableWithoutTouchingState(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        try {
            $this->project('subscription.past_due', 'k9', ['gateway_subscription_id' => 'sub_GHOST']);
            self::fail('Expected UnmappedProviderSubscriptionException.');
        } catch (UnmappedProviderSubscriptionException) {
            // expected -- retryable
        }

        self::assertNull($this->receiptFor('paystack', 'k9'));
        self::assertSame(0, $this->receiptCount());
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

    // ===========================================
    // I2 -- unbounded provider strings vs narrow receipt columns
    // ===========================================
    //
    // Every string on this path is provider-controlled and lands in a deliberately
    // narrow column (candidate_subject_type VARCHAR(10), candidate_plan_uuid
    // VARCHAR(12), provider_gateway VARCHAR(50), event_type VARCHAR(40), ...).
    // SQLite -- this harness -- silently stores an over-length value, but MySQL in
    // strict mode and PostgreSQL RAISE a data error, which inside the claim
    // transaction rolls everything back and propagates: no receipt, no diagnosis,
    // and a provider that redelivers the identical payload retries FOREVER. The
    // projector therefore clamps at its boundary; what these tests assert is that
    // the stored values ARE clamped (the observable part of the fix on any driver),
    // which is exactly what keeps the strict-driver insert inside its bounds.

    public function testOverLengthProviderMetadataStillCommitsAReceiptWithClampedCandidateValues(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => [
                'tenant_uuid' => str_repeat('T', 500),
                'subject_type' => str_repeat('S', 500),
                'subject_uuid' => str_repeat('U', 500),
                'plan_uuid' => str_repeat('P', 500),
            ],
        ]);

        // Deterministic rejection (the 500-char subject_type is not a valid subject),
        // COMMITTED -- never a rethrown data error.
        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('rejected', $receipt['outcome']);
        self::assertSame('invalid_subject', $receipt['rejection_code']);

        self::assertSame(str_repeat('T', 64), $receipt['candidate_tenant_uuid']);
        self::assertSame(str_repeat('S', 10), $receipt['candidate_subject_type']);
        self::assertSame(str_repeat('U', 64), $receipt['candidate_subject_uuid']);
        self::assertSame(str_repeat('P', 12), $receipt['candidate_plan_uuid']);

        self::assertSame('incomplete', $this->row()['status']);
        self::assertSame(0, $this->eventCount());
    }

    public function testOverLengthGatewayAndEventTypeAreClampedOnBothReceiptAndEventWithoutBreakingAcceptance(): void
    {
        $gateway = str_repeat('g', 500);
        $type = str_repeat('t', 500);
        $logicalKey = str_repeat('k', 500);

        // The row is linked using the CLAMPED gateway: clamping happens before the
        // lookup as well as before the write, so find and store stay symmetric.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => substr($gateway, 0, 50),
            'provider_subscription_id' => 'sub_X',
        ]);

        $this->project($type, $logicalKey, ['gateway_subscription_id' => 'sub_X'], gateway: $gateway);

        $receipt = $this->receiptFor(str_repeat('g', 50), str_repeat('k', 191));
        self::assertIsArray($receipt);
        self::assertSame('accepted', $receipt['outcome']);
        self::assertSame(str_repeat('g', 50), $receipt['provider_gateway']);
        self::assertSame(str_repeat('t', 40), $receipt['event_type']);
        self::assertSame(str_repeat('k', 191), $receipt['provider_logical_event_key']);

        // The subscription_events row declares the SAME widths and gets the SAME
        // clamped values -- one clamp at the boundary, both writes consistent.
        $event = $this->eventRowFor(str_repeat('g', 50), str_repeat('k', 191));
        self::assertIsArray($event);
        self::assertSame(str_repeat('t', 40), $event['type']);
        self::assertSame(1, $this->eventCount());
    }

    public function testAnOverLengthLogicalKeyStillDeduplicatesAfterClamping(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'trialing',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $key = str_repeat('k', 500);
        $this->project('subscription.past_due', $key, ['gateway_subscription_id' => 'sub_X']);
        $this->project('subscription.past_due', $key, ['gateway_subscription_id' => 'sub_X']);

        self::assertSame(1, $this->receiptCount());
        self::assertSame(1, $this->eventCount());
        self::assertIsArray($this->receiptFor('paystack', str_repeat('k', 191)));
    }

    public function testAnOverLengthGatewaySubscriptionIdIsClampedBeforeTheRelinkWrite(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $this->project('subscription.created', 'k1', [
            'gateway_subscription_id' => str_repeat('s', 500),
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ]);

        $receipt = $this->receiptFor('paystack', 'k1');
        self::assertIsArray($receipt);
        self::assertSame('accepted', $receipt['outcome']);

        // provider_subscription_id is VARCHAR(191); the relink stored the clamp.
        self::assertSame(str_repeat('s', 191), $this->row()['provider_subscription_id']);
    }
}
