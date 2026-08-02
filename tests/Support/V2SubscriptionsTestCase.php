<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Support;

use Glueful\Extensions\Subscriptions\Database\Migrations\SubjectModel;
use Glueful\Helpers\Utils;

/**
 * Isolated v2 harness (Task 5, spec §3.3 "fresh install"): applies migration
 * 006 while the shared harness's tables are still empty (zero subscription
 * rows -- the fresh-install path, no preparation marker required), then
 * seeds the platform catalog explicitly. Tasks 6-8 build their subject-model
 * tests on top of this case.
 *
 * 006 is intentionally NOT added to the shared SubscriptionsTestCase itself:
 * current production services and legacy fixture helpers cannot yet satisfy
 * `plan_uuid NOT NULL`. That coordinated activation is Task 9's job.
 */
abstract class V2SubscriptionsTestCase extends SubscriptionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new SubjectModel())->up($this->connection->getSchemaBuilder());

        $this->seedPlatformPlans();
    }

    private function seedPlatformPlans(): void
    {
        $this->connection->table('subscription_plans')->insert([
            'uuid' => 'planv2free01',
            'plan_key' => 'free',
            'display_name' => 'Free',
            'entitlements' => json_encode([
                'reports.export' => false,
                'projects.limit' => 3,
                'team.limit' => 1,
            ], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'tenant',
            'owner_tenant_uuid' => '',
        ]);
        $this->connection->table('subscription_plans')->insert([
            'uuid' => 'planv2pro001',
            'plan_key' => 'pro',
            'display_name' => 'Pro',
            'entitlements' => json_encode([
                'reports.export' => true,
                'projects.limit' => 50,
                'team.limit' => 20,
                'api.monthly' => 100000,
            ], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 1,
            'audience' => 'tenant',
            'owner_tenant_uuid' => '',
        ]);
    }

    /**
     * Overrides the 1.x-shaped parent seeding: resolves plan_key -> plan_uuid
     * against the seeded catalog scope and writes a coherent subject triple.
     * Defaults to a tenant self-subject (subject_uuid = tenant_uuid), matching
     * the parent's tenant-only fixture shape; pass subject_type/subject_uuid
     * overrides for a membership row.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function seedSubscription(array $overrides = []): array
    {
        $tenantUuid = (string) ($overrides['tenant_uuid'] ?? 'tenantA');
        $planKey = (string) ($overrides['plan_key'] ?? 'free');
        $subjectType = (string) ($overrides['subject_type'] ?? 'tenant');
        $subjectUuid = (string) ($overrides['subject_uuid'] ?? $tenantUuid);
        $audience = $subjectType === 'user' ? 'user' : 'tenant';
        $ownerTenantUuid = $subjectType === 'user' ? $tenantUuid : '';

        $plan = $this->connection->table('subscription_plans')
            ->where('plan_key', '=', $planKey)
            ->where('audience', '=', $audience)
            ->where('owner_tenant_uuid', '=', $ownerTenantUuid)
            ->first();

        if ($plan === null) {
            throw new \RuntimeException(
                "V2SubscriptionsTestCase::seedSubscription(): no plan resolves for "
                . "plan_key='{$planKey}' audience='{$audience}' owner_tenant_uuid='{$ownerTenantUuid}'. "
                . 'Seed it first (or pass an explicit plan_uuid override).'
            );
        }

        $row = array_merge([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'plan_key' => $planKey,
            'plan_uuid' => $plan['uuid'],
            'status' => 'active',
        ], $overrides);

        $this->connection->table('subscriptions')->insert($row);

        return $row;
    }
}
