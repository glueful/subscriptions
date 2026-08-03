<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Guards the SHARED harness itself: migration 006 applied, the platform catalog
 * seeded, and seedSubscription() writing a coherent subject triple + resolved
 * plan_uuid for both tenant and user subjects. Every other integration test
 * silently depends on all of that.
 */
final class SubscriptionsTestCaseSmokeTest extends SubscriptionsTestCase
{
    public function testSubjectModelIsAppliedAndPlatformCatalogIsSeeded(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        self::assertTrue($schema->hasColumn('subscriptions', 'plan_uuid'));
        self::assertTrue($schema->hasColumn('subscriptions', 'subject_type'));
        self::assertTrue($schema->hasColumn('subscriptions', 'subject_uuid'));
        self::assertTrue($schema->hasColumn('subscription_plans', 'audience'));
        self::assertTrue($schema->hasColumn('subscription_plans', 'owner_tenant_uuid'));
        self::assertTrue($schema->hasTable('subscription_provider_event_receipts'));

        self::assertSame(2, $this->connection()->table('subscription_plans')->count());
    }

    public function testSeedSubscriptionWritesCoherentTenantSubjectAndResolvedPlanUuid(): void
    {
        $row = $this->seedSubscription();

        self::assertSame('tenant', $row['subject_type']);
        self::assertSame($row['tenant_uuid'], $row['subject_uuid']);
        self::assertNotEmpty($row['plan_uuid']);

        $stored = $this->connection()->table('subscriptions')
            ->where('uuid', '=', $row['uuid'])
            ->first();
        self::assertSame($row['plan_uuid'], $stored['plan_uuid']);
        self::assertSame('active', $stored['status']);
    }

    public function testSeedSubscriptionSupportsAMemberSubjectAgainstItsOwnWorkspacePlan(): void
    {
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => 'planv2wsmem1',
            'plan_key' => 'member',
            'display_name' => 'Member',
            'entitlements' => json_encode(['content.access' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => 'tenantB',
        ]);

        $row = $this->seedSubscription([
            'tenant_uuid' => 'tenantB',
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
            'plan_key' => 'member',
        ]);

        self::assertSame('user', $row['subject_type']);
        self::assertSame('user-1', $row['subject_uuid']);
        self::assertSame('planv2wsmem1', $row['plan_uuid']);
    }
}
