<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Bridge;

use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Projection\ProjectionOutcome;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use PHPUnit\Framework\TestCase;

/**
 * A spy projector that records the DTOs it receives. (Constructor property
 * promotion cannot be by-reference, so the spy holds its own public state.)
 *
 * Both entry points record into the SAME `$captured` list (Task 12): the strict
 * bridge's parity test drives one call through project() (via the ordinary bus's
 * __invoke()) and one through projectWithOutcome() (via handle()) and compares
 * them, so a spy that only tracked one method would silently break that proof.
 */
final class SpyProjector implements SubscriptionEventProjectorInterface
{
    /** @var list<ProviderSubscriptionEvent> */
    public array $captured = [];

    public function project(ProviderSubscriptionEvent $event): void
    {
        $this->captured[] = $event;
    }

    public function projectWithOutcome(ProviderSubscriptionEvent $event): ProjectionOutcome
    {
        $this->captured[] = $event;

        return ProjectionOutcome::accepted($event->logicalEventKey);
    }
}

final class PayviaSubscriptionEventBridgeTest extends TestCase
{
    public function testAdaptsPayviaEventShapeIntoOneProjectorCall(): void
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

    public function testIgnoresEventWithoutInnerObject(): void
    {
        $projector = new SpyProjector();
        (new PayviaSubscriptionEventBridge($projector))(new class {
            public ?object $event = null;
        });
        self::assertCount(0, $projector->captured);
    }
}
