<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Bridge;

use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use PHPUnit\Framework\TestCase;

/**
 * A spy projector that records the DTOs it receives. (Constructor property
 * promotion cannot be by-reference, so the spy holds its own public state.)
 */
final class SpyProjector implements SubscriptionEventProjectorInterface
{
    /** @var list<ProviderSubscriptionEvent> */
    public array $captured = [];

    public function project(ProviderSubscriptionEvent $event): void
    {
        $this->captured[] = $event;
    }
}

final class PayviaSubscriptionEventBridgeTest extends TestCase
{
    public function test_adapts_payvia_event_shape_into_one_projector_call(): void
    {
        $projector = new SpyProjector();

        // Payvia's wrapper: an object with ->event exposing the inner accessors.
        $inner = new class {
            public function gateway(): string
            {
                return 'stripe';
            }

            public function type(): string
            {
                return 'subscription.created';
            }

            public function logicalEventKey(): string
            {
                return 'sub_1:created';
            }

            /** @return array<string,mixed> */
            public function normalized(): array
            {
                return ['gateway_subscription_id' => 'sub_1'];
            }
        };
        $payviaEvent = new class ($inner) {
            public function __construct(public object $event)
            {
            }
        };

        (new PayviaSubscriptionEventBridge($projector))($payviaEvent);

        self::assertCount(1, $projector->captured);
        self::assertSame('stripe', $projector->captured[0]->gateway);
        self::assertSame('subscription.created', $projector->captured[0]->type);
        self::assertSame('sub_1:created', $projector->captured[0]->logicalEventKey);
        self::assertSame('sub_1', $projector->captured[0]->normalized['gateway_subscription_id']);
    }

    public function test_ignores_event_without_inner_object(): void
    {
        $projector = new SpyProjector();
        (new PayviaSubscriptionEventBridge($projector))(new class {
            public ?object $event = null;
        });
        self::assertCount(0, $projector->captured);
    }
}
