<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Projection;

use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use PHPUnit\Framework\TestCase;

final class ProviderSubscriptionEventTest extends TestCase
{
    public function test_exposes_readonly_fields(): void
    {
        $event = new ProviderSubscriptionEvent(
            gateway: 'stripe',
            type: 'subscription.created',
            logicalEventKey: 'sub_1:created',
            normalized: ['gateway_subscription_id' => 'sub_1', 'status' => 'active'],
        );

        self::assertSame('stripe', $event->gateway);
        self::assertSame('subscription.created', $event->type);
        self::assertSame('sub_1:created', $event->logicalEventKey);
        self::assertSame('sub_1', $event->normalized['gateway_subscription_id']);
    }
}
