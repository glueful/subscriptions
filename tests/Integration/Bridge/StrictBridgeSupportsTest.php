<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Bridge;

use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Bridge\StrictPayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Projection\UnmappedProviderSubscriptionException;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\RecordingTenantContextRunner;
use Glueful\Extensions\Subscriptions\Tests\Support\StrictFakeProviderEvent;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * The strict payvia lane adapter (Task 5, spec §4). Unlike the fault-isolated
 * ordinary bus lane (PayviaSubscriptionEventBridgeTest), this bridge is wired
 * into payvia's opt-in `StrictPaymentEventListener` composition: `supports()`
 * gates routing on a closed six-type set and a non-empty gateway subscription
 * id, plus -- for the five NON-created types -- an ownership proof (a local row
 * already linked to that (gateway, id) pair, found under system mode, or a
 * strict metadata marker).
 *
 * SPEC-OWNER RULING covered here: `subscription.created` requires NO ownership
 * proof. At creation time no local link exists by definition, and the legacy
 * tenant-metadata relink shape (`metadata.tenant_uuid`, no `glueful_consumer`)
 * carries no marker -- so demanding proof would strand every 1.x-shaped
 * checkout. The projector stays the sole subject/scope/receipt/rejection/retry
 * authority after routing, which is what makes the widening safe; the accepted
 * cost is foreign created-event retries.
 *
 * `handle()` delegates to the SAME `PayviaSubscriptionEventBridge::projectInner()`
 * entry the ordinary lane's `__invoke()` uses, so both lanes stay structurally
 * incapable of drifting apart -- proven here by the parity test AND by the
 * end-to-end relink test that drives both lanes through a REAL projector.
 */
final class StrictBridgeSupportsTest extends SubscriptionsTestCase
{
    private const SIX_SUPPORTED_TYPES = [
        'subscription.created',
        'subscription.updated',
        'subscription.past_due',
        'subscription.canceled',
        'payment.succeeded',
        'invoice.paid',
    ];

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

    private function neutralBridge(SpyProjector $spy): PayviaSubscriptionEventBridge
    {
        return new PayviaSubscriptionEventBridge($spy);
    }

    private function strictBridge(PayviaSubscriptionEventBridge $neutral): StrictPayviaSubscriptionEventBridge
    {
        return new StrictPayviaSubscriptionEventBridge($neutral, new SubscriptionRepository(), $this->appContext());
    }

    public function testSupportsTrueForEachSupportedTypeWithLinkedLocalRowAndNoMarker(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_1',
        ]);

        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        foreach (self::SIX_SUPPORTED_TYPES as $type) {
            $event = $this->event($type, ['gateway_subscription_id' => 'sub_1']);
            self::assertTrue($strict->supports($event), "expected supports() === true for {$type}");
        }
    }

    public function testSupportsTrueForSubscriptionCreatedWithNoLocalRowButConsumerMarker(): void
    {
        // The checkout race: the tenant's local row hasn't been provider-linked
        // yet, but the event itself is tagged for subscriptions.
        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        $event = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_NEW',
            'metadata' => ['glueful_consumer' => 'subscriptions'],
        ]);

        self::assertTrue($strict->supports($event));
    }

    /**
     * SPEC-OWNER RULING: this row FLIPPED. An unmapped, unmarked
     * `subscription.created` used to be false; it is now supported outright,
     * because created-time events have no local link yet and the legacy
     * tenant-metadata relink shape carries no marker.
     */
    public function testSupportsTrueForCreatedWhenUnmappedAndNoMarker(): void
    {
        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        $event = $this->event('subscription.created', ['gateway_subscription_id' => 'sub_GHOST']);

        self::assertTrue($strict->supports($event));
    }

    /**
     * The other side of the same matrix row: for the five NON-created types the
     * full ownership proof still applies, so unmapped + unmarked stays false.
     */
    public function testSupportsFalseForNonCreatedTypesWhenUnmappedAndNoMarker(): void
    {
        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        foreach (self::SIX_SUPPORTED_TYPES as $type) {
            if ($type === 'subscription.created') {
                continue;
            }
            $event = $this->event($type, ['gateway_subscription_id' => 'sub_GHOST']);
            self::assertFalse($strict->supports($event), "expected supports() === false for {$type}");
        }
    }

    /**
     * Marker strictness is only load-bearing for the non-created types now, so
     * this matrix is driven with `subscription.updated`: a lookalike marker must
     * not be accepted as ownership proof.
     */
    public function testSupportsFalseForHostileLookalikeMarkers(): void
    {
        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        $capitalized = $this->event('subscription.updated', [
            'gateway_subscription_id' => 'sub_GHOST',
            'metadata' => ['glueful_consumer' => 'Subscriptions'],
        ]);
        self::assertFalse($strict->supports($capitalized), 'capitalized marker must not match (strict ===)');

        $padded = $this->event('subscription.updated', [
            'gateway_subscription_id' => 'sub_GHOST',
            'metadata' => ['glueful_consumer' => ' subscriptions '],
        ]);
        self::assertFalse($strict->supports($padded), 'padded marker must not match (strict ===)');

        $nested = $this->event('subscription.updated', [
            'gateway_subscription_id' => 'sub_GHOST',
            'metadata' => ['glueful_consumer' => ['subscriptions']],
        ]);
        self::assertFalse($strict->supports($nested), 'nested-array marker must not match (strict ===)');
    }

    public function testSupportsFalseForUnknownTypeEvenWithAnId(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_1',
        ]);

        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        // Not in SUPPORTED_TYPES, even though it has a linked, ownable id.
        $event = $this->event('subscription.trial_will_end', ['gateway_subscription_id' => 'sub_1']);

        self::assertFalse($strict->supports($event));
    }

    public function testSupportsFalseForSupportedTypeWithMissingOrEmptyId(): void
    {
        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        $missing = $this->event('subscription.updated', []);
        self::assertFalse($strict->supports($missing));

        $empty = $this->event('subscription.updated', ['gateway_subscription_id' => '']);
        self::assertFalse($strict->supports($empty));
    }

    public function testSupportsFalseForOneOffPaymentSucceededWithoutId(): void
    {
        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        $event = $this->event('payment.succeeded', []);

        self::assertFalse($strict->supports($event));
    }

    public function testHandleDelegatesToTheSameProjectionEntryAsInvoke(): void
    {
        $spy = new SpyProjector();
        $neutral = $this->neutralBridge($spy);
        $strict = $this->strictBridge($neutral);

        $event = $this->event(
            'subscription.updated',
            ['gateway_subscription_id' => 'sub_1', 'status' => 'past_due'],
            gateway: 'stripe',
            logicalEventKey: 'evt:parity',
        );

        // __invoke() path: the ordinary bus wrapper unwraps ->event first.
        $wrapper = new class ($event) {
            public function __construct(public object $event)
            {
            }
        };
        $neutral($wrapper);

        // handle() path: payvia hands the strict lane the event directly.
        $strict->handle($event);

        self::assertCount(2, $spy->captured);
        [$viaInvoke, $viaHandle] = $spy->captured;

        self::assertSame($viaInvoke->gateway, $viaHandle->gateway);
        self::assertSame($viaInvoke->type, $viaHandle->type);
        self::assertSame($viaInvoke->logicalEventKey, $viaHandle->logicalEventKey);
        self::assertSame($viaInvoke->normalized, $viaHandle->normalized);

        self::assertSame('stripe', $viaHandle->gateway);
        self::assertSame('subscription.updated', $viaHandle->type);
        self::assertSame('evt:parity', $viaHandle->logicalEventKey);
        self::assertSame(['gateway_subscription_id' => 'sub_1', 'status' => 'past_due'], $viaHandle->normalized);
    }

    public function testSupportsRepositoryLookupRunsUnderSystemMode(): void
    {
        $runner = new RecordingTenantContextRunner();
        $this->bind(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class, $runner);

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_1',
        ]);

        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        $event = $this->event('subscription.updated', ['gateway_subscription_id' => 'sub_1']);
        self::assertTrue($strict->supports($event));

        self::assertNotSame([], $runner->calls());
        foreach ($runner->calls() as $call) {
            self::assertSame('system', $call['mode']);
            self::assertNull($call['tenantUuid']);
        }
    }

    /**
     * The REAL projector, wired exactly as `SubscriptionsServiceProvider` wires
     * it -- needed by the two ruling-mandated end-to-end tests below, which have
     * to observe actual database effects rather than a spy's captured DTOs.
     */
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

    /** @return array<string,mixed>|null */
    private function receiptFor(string $gateway, string $key): ?array
    {
        return $this->connection()->table('subscription_provider_event_receipts')
            ->where('provider_gateway', '=', $gateway)
            ->where('provider_logical_event_key', '=', $key)
            ->first();
    }

    /** @return array<string,mixed> */
    private function subscriptionRow(string $tenantUuid): array
    {
        $row = $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->first();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * RULING TEST (a): the legacy tenant-metadata relink flow, END-TO-END through
     * the strict lane. An UNLINKED local row plus a `subscription.created` event
     * carrying only `metadata.tenant_uuid` (NO `glueful_consumer` marker, no
     * pre-existing (gateway, id) link) is the exact 1.x shape the neutral bus
     * lane always accepted. Under the widened `supports()` it is SUPPORTED, and
     * handling it links and projects identically to the bus path -- driven here
     * side by side against a REAL projector so "identically" is observed, not
     * asserted by inspection.
     */
    public function testLegacyTenantMetadataRelinkThroughTheStrictLaneMatchesTheNeutralBusPath(): void
    {
        // Two identical, deliberately UNLINKED rows (no provider_subscription_id).
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'incomplete']);
        $this->seedSubscription(['tenant_uuid' => 'tenantB', 'plan_key' => 'pro', 'status' => 'incomplete']);

        $neutral = new PayviaSubscriptionEventBridge($this->realProjector());
        $strict = $this->strictBridge($neutral);

        $strictEvent = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_STRICT',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantA'], // NO glueful_consumer marker
        ], gateway: 'paystack', logicalEventKey: 'evt:strict');

        // Supported with no ownership proof whatsoever -- the ruling's whole point.
        self::assertTrue($strict->supports($strictEvent));
        $strict->handle($strictEvent);

        // The same shape down the ordinary bus lane, for a twin tenant.
        $busEvent = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_BUS',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantB'],
        ], gateway: 'paystack', logicalEventKey: 'evt:bus');
        $neutral(new class ($busEvent) {
            public function __construct(public object $event)
            {
            }
        });

        $strictRow = $this->subscriptionRow('tenantA');
        $busRow = $this->subscriptionRow('tenantB');

        // Linked by the relink recovery, through BOTH lanes.
        self::assertSame('paystack', $strictRow['provider_gateway']);
        self::assertSame('sub_STRICT', $strictRow['provider_subscription_id']);
        self::assertSame('paystack', $busRow['provider_gateway']);
        self::assertSame('sub_BUS', $busRow['provider_subscription_id']);

        // Projected identically: same resulting status, same accepted receipt shape.
        self::assertSame($busRow['status'], $strictRow['status']);
        self::assertNotSame('incomplete', $strictRow['status']); // the event actually projected

        $strictReceipt = $this->receiptFor('paystack', 'evt:strict');
        $busReceipt = $this->receiptFor('paystack', 'evt:bus');
        self::assertIsArray($strictReceipt);
        self::assertIsArray($busReceipt);
        self::assertSame('accepted', $strictReceipt['outcome']);
        self::assertSame($busReceipt['outcome'], $strictReceipt['outcome']);
        self::assertNull($strictReceipt['rejection_code']);
        self::assertSame('tenantA', $strictReceipt['tenant_uuid']);
        self::assertSame('tenant', $strictReceipt['subject_type']);
        self::assertSame($busReceipt['subject_type'], $strictReceipt['subject_type']);
    }

    /**
     * RULING TEST (b): the accepted cost. A created event whose metadata is
     * valid-shaped but names a tenant with NO local subscription row is still
     * SUPPORTED (no ownership proof is demanded), and `handle()` must let the
     * projector's RETRYABLE `UnmappedProviderSubscriptionException` escape --
     * uncaught -- so payvia's strict lane releases the lease and redelivers.
     * Catching it here would convert a retryable delivery into a silent drop.
     */
    public function testCreatedEventWithNoLocalRowIsSupportedAndSurfacesTheRetryableUnmappedException(): void
    {
        $strict = $this->strictBridge(new PayviaSubscriptionEventBridge($this->realProjector()));

        $event = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_FOREIGN',
            'status' => 'active',
            'metadata' => ['tenant_uuid' => 'tenantNOBODY'],
        ], gateway: 'paystack', logicalEventKey: 'evt:unmapped');

        self::assertTrue($strict->supports($event));

        $this->expectException(UnmappedProviderSubscriptionException::class);
        try {
            $strict->handle($event);
        } finally {
            // Retryable means NOTHING durable remembers the attempt: the pending
            // receipt claim rolled back with the rest of the transaction.
            self::assertNull($this->receiptFor('paystack', 'evt:unmapped'));
        }
    }
}
