<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Bridge;

use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Bridge\StrictPayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\RecordingTenantContextRunner;
use Glueful\Extensions\Subscriptions\Tests\Support\StrictFakeProviderEvent;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * The ownership-aware strict payvia lane adapter (Task 5, spec §4). Unlike the
 * fault-isolated ordinary bus lane (PayviaSubscriptionEventBridgeTest), this
 * bridge is wired into payvia's opt-in `StrictPaymentEventListener` composition:
 * `supports()` gates delivery on a closed six-type set, a non-empty gateway
 * subscription id, and an ownership proof -- either a local row already linked
 * to that (gateway, id) pair, found under system mode, or a strict metadata
 * marker for the checkout race where no local link exists yet.
 *
 * `handle()` delegates to the SAME `PayviaSubscriptionEventBridge::projectInner()`
 * entry the ordinary lane's `__invoke()` uses, so both lanes stay structurally
 * incapable of drifting apart -- proven here by the parity test.
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

    public function testSupportsFalseWhenUnmappedAndNoMarker(): void
    {
        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        $event = $this->event('subscription.created', ['gateway_subscription_id' => 'sub_GHOST']);

        self::assertFalse($strict->supports($event));
    }

    public function testSupportsFalseForHostileLookalikeMarkers(): void
    {
        $strict = $this->strictBridge($this->neutralBridge(new SpyProjector()));

        $capitalized = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_GHOST',
            'metadata' => ['glueful_consumer' => 'Subscriptions'],
        ]);
        self::assertFalse($strict->supports($capitalized), 'capitalized marker must not match (strict ===)');

        $padded = $this->event('subscription.created', [
            'gateway_subscription_id' => 'sub_GHOST',
            'metadata' => ['glueful_consumer' => ' subscriptions '],
        ]);
        self::assertFalse($strict->supports($padded), 'padded marker must not match (strict ===)');

        $nested = $this->event('subscription.created', [
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
}
