<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Helpers\Utils;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

final class MigrationsTest extends SubscriptionsTestCase
{
    public function testTablesExist(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        self::assertTrue($schema->hasTable('subscriptions'));
        self::assertTrue($schema->hasTable('subscription_overrides'));
        self::assertTrue($schema->hasTable('subscription_events'));
    }

    public function testTenantUuidIsUniqueAndFitsOpaqueExternalIds(): void
    {
        // S-id: tenant_uuid is an opaque external id (string(64)) -- a 36-char UUID
        // (or anything up to 64 chars) must round-trip untouched.
        $longTenant = str_repeat('a1b2-', 12) . 'zzzz'; // 64 chars
        self::assertSame(64, strlen($longTenant));
        $this->seedSubscription(['tenant_uuid' => $longTenant]);

        $row = $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', $longTenant)
            ->first();
        self::assertNotNull($row);
        self::assertSame($longTenant, $row['tenant_uuid']);

        // S1: one current subscription per tenant -- second row for the same tenant throws.
        $this->expectException(\Throwable::class);
        $this->seedSubscription(['tenant_uuid' => $longTenant]);
    }

    public function testPayviaSubscriptionUniquePerGatewayWithNullsUnconstrained(): void
    {
        // Multiple all-NULL payvia rows (free/comp subscriptions) are unconstrained.
        $this->seedSubscription(['tenant_uuid' => 'tenantA']);
        $this->seedSubscription(['tenant_uuid' => 'tenantB']);

        // The same provider subscription id under DIFFERENT gateways is two distinct rows.
        $this->seedSubscription([
            'tenant_uuid' => 'tenantC',
            'payvia_gateway' => 'stripe',
            'payvia_subscription_id' => 'sub_X',
        ]);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantD',
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
        ]);

        self::assertSame(4, $this->connection()->table('subscriptions')->count());

        // Same (gateway, provider-subscription) pair twice throws.
        $this->expectException(\Throwable::class);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantE',
            'payvia_gateway' => 'stripe',
            'payvia_subscription_id' => 'sub_X',
        ]);
    }

    public function testOverrideUniqueKey(): void
    {
        $row = [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'entitlement' => 'projects.limit',
            'value' => json_encode(10, JSON_THROW_ON_ERROR),
        ];

        $this->connection()->table('subscription_overrides')->insert($row);
        $this->expectException(\Throwable::class);
        $this->connection()->table('subscription_overrides')->insert(array_merge($row, [
            'uuid' => Utils::generateNanoID(12),
        ]));
    }

    public function testPayviaEventDedupeIsGatewayScopedAndAllowsNullLogicalKeys(): void
    {
        $base = [
            'tenant_uuid' => 'tenantA',
            'type' => 'subscription.updated',
            'source' => 'payvia_event',
            'payvia_logical_event_key' => 'subscription.updated:sub_1:v1',
        ];

        $this->connection()->table('subscription_events')->insert(array_merge($base, [
            'uuid' => Utils::generateNanoID(12),
            'payvia_gateway' => 'stripe',
        ]));
        $this->connection()->table('subscription_events')->insert(array_merge($base, [
            'uuid' => Utils::generateNanoID(12),
            'payvia_gateway' => 'paystack',
        ]));

        $this->connection()->table('subscription_events')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'type' => 'manual',
            'source' => 'manual',
            'payvia_gateway' => null,
            'payvia_logical_event_key' => null,
        ]);
        $this->connection()->table('subscription_events')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'type' => 'manual',
            'source' => 'manual',
            'payvia_gateway' => null,
            'payvia_logical_event_key' => null,
        ]);

        self::assertSame(4, $this->connection()->table('subscription_events')->count());

        $this->expectException(\Throwable::class);
        $this->connection()->table('subscription_events')->insert(array_merge($base, [
            'uuid' => Utils::generateNanoID(12),
            'payvia_gateway' => 'stripe',
        ]));
    }
}
