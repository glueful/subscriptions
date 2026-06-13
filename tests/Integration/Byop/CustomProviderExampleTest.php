<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Byop;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderSubscriptionEvent;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

final class CustomProviderExampleTest extends SubscriptionsTestCase
{
    public function testCustomProviderDrivesSubscriptionStateWithoutPayvia(): void
    {
        self::assertFalse(
            class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class, false),
            'BYOP example must not rely on payvia being loaded'
        );

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'status' => 'past_due',
            'provider_gateway' => 'acme',
            'provider_subscription_id' => 'acme_1',
        ]);

        // A custom bridge would build the projector exactly like this and call it:
        $projector = new SubscriptionEventProjector(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
        );
        $projector->project(new ProviderSubscriptionEvent(
            gateway: 'acme',
            type: 'payment.succeeded',
            logicalEventKey: 'acme_1:paid:1',
            normalized: ['gateway_subscription_id' => 'acme_1', 'current_period_end' => '2030-01-01 00:00:00'],
        ));

        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', '=', 'tenantA')->first();
        self::assertSame('active', $row['status']); // settled from past_due

        // And a custom puller drives reconcile:
        $puller = new class implements ProviderStatePullerInterface {
            public function pull(string $gateway, string $providerSubscriptionId): ?array
            {
                return ['status' => 'canceled'];
            }
        };
        $service = new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            $puller,
        );
        $service->reconcile('tenantA');

        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', '=', 'tenantA')->first();
        self::assertSame('canceled', $row['status']);
    }
}
