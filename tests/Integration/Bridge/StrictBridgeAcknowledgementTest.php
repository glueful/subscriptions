<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Bridge;

use Glueful\Extensions\Payvia\Contracts\SubscriptionProjectionAcknowledger;
use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Bridge\StrictPayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Projection\UnmappedProviderSubscriptionException;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\StrictFakeProviderEvent;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 12 (design spec §3.6/§4.3): `StrictPayviaSubscriptionEventBridge::handle()`'s
 * payvia acknowledgement -- called through the outcome-returning projector entry
 * point, AFTER the receipt transaction has committed, only for the
 * activation-bearing `subscription.created` event, and only when the event
 * actually correlates to a Payvia checkout (a non-empty `metadata.origination_uuid`).
 * A present `SubscriptionProjectionAcknowledger` contract with no resolvable
 * container binding is a hard, uncaught failure (fail closed / retryable).
 */
final class StrictBridgeAcknowledgementTest extends SubscriptionsTestCase
{
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

    private function realProjector(): SubscriptionEventProjector
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

    private function strictBridge(): StrictPayviaSubscriptionEventBridge
    {
        return new StrictPayviaSubscriptionEventBridge(
            new PayviaSubscriptionEventBridge($this->realProjector()),
            new SubscriptionRepository(),
            $this->appContext(),
        );
    }

    /** @param array<string,mixed> $normalized */
    private function event(
        string $type,
        array $normalized,
        string $gateway = 'stripe',
        string $logicalEventKey = 'evt:1',
    ): StrictFakeProviderEvent {
        return new StrictFakeProviderEvent(
            gateway: $gateway,
            type: $type,
            providerEventId: 'pe_1',
            deliveryKey: 'delivery-1',
            logicalEventKey: $logicalEventKey,
            occurredAt: new \DateTimeImmutable('now'),
            normalized: $normalized,
            raw: [],
        );
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
    // Ordering: never before the receipt transaction commits
    // ===========================================

    public function testAcknowledgesOnlyAfterTheReceiptIsAlreadyDurablyCommitted(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-NEW');

        $acknowledger = new RecordingAcknowledger(function () {
            // Called from INSIDE acknowledge() -- if this observes anything other
            // than the already-settled receipt, the bridge acknowledged too early.
            return $this->receiptFor('stripe', 'evt:created');
        });
        $this->bind(SubscriptionProjectionAcknowledger::class, $acknowledger);

        $event = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'origination_uuid' => 'origination-NEW'],
        ], gateway: 'stripe', logicalEventKey: 'evt:created');

        $this->strictBridge()->handle($event);

        self::assertCount(1, $acknowledger->calls);
        $snapshotAtAckTime = $acknowledger->snapshots[0];
        self::assertIsArray($snapshotAtAckTime, 'the receipt must already exist by the time acknowledge() runs');
        self::assertSame('accepted', $snapshotAtAckTime['outcome']);

        [$originationUuid, $consumer, $logicalEventKey, $outcome, $reason] = $acknowledger->calls[0];
        self::assertSame('origination-NEW', $originationUuid);
        self::assertSame('subscriptions', $consumer);
        self::assertSame('evt:created', $logicalEventKey);
        self::assertSame('accepted', $outcome);
        self::assertNull($reason);
    }

    public function testAcknowledgesARejectedOutcomeWithItsReasonCode(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-NEW');

        $acknowledger = new RecordingAcknowledger();
        $this->bind(SubscriptionProjectionAcknowledger::class, $acknowledger);

        // A stale/historical origination echoed back -- the origination_mismatch
        // rejection posture from design spec §3.3.
        $event = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_STALE',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'origination_uuid' => 'origination-OLD'],
        ], gateway: 'stripe', logicalEventKey: 'evt:stale');

        $this->strictBridge()->handle($event);

        self::assertCount(1, $acknowledger->calls);
        [$originationUuid, $consumer, $logicalEventKey, $outcome, $reason] = $acknowledger->calls[0];
        // Acknowledges against the EVENT's own (stale) origination_uuid -- the
        // one payvia's finalizer will resolve the conflicted row through --
        // never the newer reservation's origination.
        self::assertSame('origination-OLD', $originationUuid);
        self::assertSame('subscriptions', $consumer);
        self::assertSame('evt:stale', $logicalEventKey);
        self::assertSame('rejected', $outcome);
        self::assertSame('origination_mismatch', $reason);
    }

    // ===========================================
    // Scope: subscription.created only
    // ===========================================

    public function testNeverAttemptsAcknowledgementForNonCreatedEventTypes(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_1',
        ]);

        $acknowledger = new RecordingAcknowledger();
        $this->bind(SubscriptionProjectionAcknowledger::class, $acknowledger);

        $event = $this->event('subscription.updated', [
            'gateway_subscription_id' => 'sub_1',
            'status' => 'past_due',
            'metadata' => ['origination_uuid' => 'origination-WHATEVER'],
        ]);

        $this->strictBridge()->handle($event);

        self::assertSame([], $acknowledger->calls);
    }

    public function testNeverAttemptsAcknowledgementWhenTheEventCarriesNoOriginationUuid(): void
    {
        // Pre-2.2 legacy shape: no origination at all -- nothing to acknowledge.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'pro',
            'status' => 'incomplete',
        ]);

        $acknowledger = new RecordingAcknowledger();
        $this->bind(SubscriptionProjectionAcknowledger::class, $acknowledger);

        $event = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'],
        ], gateway: 'paystack', logicalEventKey: 'evt:legacy');

        $this->strictBridge()->handle($event);

        self::assertSame([], $acknowledger->calls);
        self::assertSame('active', $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', 'tenantA')->first()['status']);
    }

    // ===========================================
    // Fail-closed: contract present, no resolvable binding
    // ===========================================

    public function testThrowsWhenTheContractExistsButNoBindingIsRegistered(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-NEW');

        // No SubscriptionProjectionAcknowledger bound at all in this test's container.
        $event = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'origination_uuid' => 'origination-NEW'],
        ], gateway: 'stripe', logicalEventKey: 'evt:created');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SubscriptionProjectionAcknowledger/');

        try {
            $this->strictBridge()->handle($event);
        } finally {
            // The projection itself already committed -- only the acknowledgement
            // failed. This is what makes the failure retryable rather than a lost
            // event: a redelivery will find the SAME settled receipt and duplicate
            // through storedOutcomeOrFail() rather than re-projecting.
            $row = $this->connection()->table('subscriptions')
                ->where('tenant_uuid', '=', 'tenantA')->first();
            self::assertSame('active', $row['status']);
        }
    }

    public function testThrowsWhenTheResolvedBindingIsNotTheExpectedType(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->reserveCheckoutFor($subject, self::PLATFORM_PRO, 'origination-NEW');

        $this->bind(SubscriptionProjectionAcknowledger::class, new \stdClass());

        $event = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_NEW',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA', 'origination_uuid' => 'origination-NEW'],
        ], gateway: 'stripe', logicalEventKey: 'evt:created');

        $this->expectException(\RuntimeException::class);
        $this->strictBridge()->handle($event);
    }

    // ===========================================
    // Unmapped/transient projection: no acknowledgement attempted at all
    // ===========================================

    public function testUnmappedProjectionSurfacesUncaughtWithoutEverCallingTheAcknowledger(): void
    {
        $acknowledger = new RecordingAcknowledger();
        $this->bind(SubscriptionProjectionAcknowledger::class, $acknowledger);

        $event = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_FOREIGN',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantNOBODY', 'origination_uuid' => 'origination-X'],
        ], gateway: 'paystack', logicalEventKey: 'evt:unmapped');

        $this->expectException(UnmappedProviderSubscriptionException::class);
        try {
            $this->strictBridge()->handle($event);
        } finally {
            self::assertSame([], $acknowledger->calls);
        }
    }
}

/**
 * Records every acknowledge() call's arguments plus (optionally) a caller-supplied
 * snapshot taken from INSIDE the call -- used to prove the receipt is already
 * durably committed by the time acknowledgement happens (design spec §4.3's
 * "never before the receipt transaction commits").
 */
final class RecordingAcknowledger implements SubscriptionProjectionAcknowledger
{
    /** @var list<array{0:string,1:string,2:string,3:string,4:?string}> */
    public array $calls = [];

    /** @var list<mixed> */
    public array $snapshots = [];

    public function __construct(private readonly ?\Closure $snapshot = null)
    {
    }

    public function acknowledge(
        string $originationUuid,
        string $consumer,
        string $logicalEventKey,
        string $outcome,
        ?string $reason = null,
    ): void {
        $this->calls[] = [$originationUuid, $consumer, $logicalEventKey, $outcome, $reason];
        $this->snapshots[] = $this->snapshot !== null ? ($this->snapshot)() : null;
    }
}
