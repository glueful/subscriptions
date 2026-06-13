<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

final class SubscriptionRepositoryTest extends SubscriptionsTestCase
{
    public function testFindByTenant(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);
        $repo = new SubscriptionRepository();

        $row = $repo->findByTenant($this->appContext(), 'tenantA');
        self::assertIsArray($row);
        self::assertSame('pro', $row['plan_key']);
        self::assertSame('active', $row['status']);

        self::assertNull($repo->findByTenant($this->appContext(), 'ghost'));
    }

    public function testFindByProviderSubscriptionIsGatewayScoped(): void
    {
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_X',
        ]);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantB',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);
        $repo = new SubscriptionRepository();

        $row = $repo->findByProviderSubscription($this->appContext(), 'paystack', 'sub_X');
        self::assertIsArray($row);
        self::assertSame('tenantB', $row['tenant_uuid']);

        self::assertNull($repo->findByProviderSubscription($this->appContext(), 'flutterwave', 'sub_X'));
    }

    public function testActiveOverridesExcludeExpired(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free', 'status' => 'active']);

        $this->connection()->table('subscription_overrides')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'entitlement' => 'projects.limit',
            'value' => json_encode(999, JSON_THROW_ON_ERROR),
            'expires_at' => null,
        ]);
        $this->connection()->table('subscription_overrides')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'entitlement' => 'reports.export',
            'value' => json_encode(true, JSON_THROW_ON_ERROR),
            'expires_at' => '2020-01-01 00:00:00',
        ]);

        $overrides = (new OverrideRepository())->activeForTenant($this->appContext(), 'tenantA');

        self::assertSame(['projects.limit' => 999], $overrides);
        self::assertArrayNotHasKey('reports.export', $overrides);
    }
}
