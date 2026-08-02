<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Tests\Support\V2SubscriptionsTestCase;

/**
 * Smoke test for the isolated v2 harness (Task 5, spec §3.3): proves 006 is
 * applied, the platform catalog is seeded, and seedSubscription() writes a
 * coherent subject triple + resolved plan_uuid for both tenant and user
 * subjects. Tasks 6-8 build their own tests on V2SubscriptionsTestCase; this
 * one only guards the harness itself.
 */
final class V2SubscriptionsTestCaseSmokeTest extends V2SubscriptionsTestCase
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
