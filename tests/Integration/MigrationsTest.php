<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Helpers\Utils;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use PDO;

final class MigrationsTest extends SubscriptionsTestCase
{
    public function testTablesExist(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        self::assertTrue($schema->hasTable('subscriptions'));
        self::assertTrue($schema->hasTable('subscription_overrides'));
        self::assertTrue($schema->hasTable('subscription_events'));
        self::assertTrue($schema->hasTable('subscription_plans'));
    }

    public function testSubscriptionPlansTableShape(): void
    {
        $columns = $this->subscriptionPlanColumns();

        self::assertSame([
            'id',
            'uuid',
            'plan_key',
            'display_name',
            'description',
            'entitlements',
            'payvia_priced_plan_uuid',
            'status',
            'sort_order',
            'created_at',
            'updated_at',
        ], array_keys($columns));

        $indexes = $this->subscriptionPlanIndexes();

        self::assertTrue($this->hasUniqueIndexOn($indexes, ['uuid']));
        self::assertTrue($this->hasUniqueIndexOn($indexes, ['plan_key']));
        self::assertTrue($this->hasIndexOn($indexes, ['updated_at']));
    }

    public function testSubscriptionPlansUniqueKeys(): void
    {
        $this->seedSubscriptionPlan([
            'uuid' => 'plan_uuid_01',
            'plan_key' => 'pro',
        ]);

        $this->expectException(\Throwable::class);
        $this->seedSubscriptionPlan([
            'uuid' => 'plan_uuid_02',
            'plan_key' => 'pro',
        ]);
    }

    public function testSubscriptionPlanUuidIsUnique(): void
    {
        $this->seedSubscriptionPlan([
            'uuid' => 'plan_uuid_01',
            'plan_key' => 'pro',
        ]);

        $this->expectException(\Throwable::class);
        $this->seedSubscriptionPlan([
            'uuid' => 'plan_uuid_01',
            'plan_key' => 'business',
        ]);
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

    /** @return array<string,array<string,mixed>> */
    private function subscriptionPlanColumns(): array
    {
        $stmt = $this->connection()->getPDO()->query('PRAGMA table_info(subscription_plans)');
        $columns = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[(string) $column['name']] = $column;
        }

        return $columns;
    }

    /**
     * @return list<array{unique:bool,columns:list<string>}>
     */
    private function subscriptionPlanIndexes(): array
    {
        $pdo = $this->connection()->getPDO();
        $stmt = $pdo->query('PRAGMA index_list(subscription_plans)');
        $indexes = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index) {
            $info = $pdo->query('PRAGMA index_info(' . $index['name'] . ')');
            $indexes[] = [
                'unique' => (bool) $index['unique'],
                'columns' => array_map(
                    static fn (array $row): string => (string) $row['name'],
                    $info->fetchAll(PDO::FETCH_ASSOC)
                ),
            ];
        }

        return $indexes;
    }

    /**
     * @param list<array{unique:bool,columns:list<string>}> $indexes
     * @param list<string>                                 $columns
     */
    private function hasUniqueIndexOn(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{unique:bool,columns:list<string>}> $indexes
     * @param list<string>                                 $columns
     */
    private function hasIndexOn(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if ($index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $overrides */
    private function seedSubscriptionPlan(array $overrides = []): void
    {
        $this->connection()->table('subscription_plans')->insert(array_merge([
            'uuid' => Utils::generateNanoID(12),
            'plan_key' => 'pro',
            'display_name' => 'Pro',
            'description' => null,
            'entitlements' => json_encode(['projects.limit' => 10], JSON_THROW_ON_ERROR),
            'payvia_priced_plan_uuid' => null,
            'status' => 'active',
            'sort_order' => 10,
        ], $overrides));
    }
}
