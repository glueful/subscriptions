<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Catalog;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Tests\Support\V2SubscriptionsTestCase;
use Glueful\Helpers\Utils;

/**
 * Task 7: the scoped, DB-authoritative PlanCatalog::forScope() path. Runs on the
 * post-006 V2SubscriptionsTestCase harness, which already seeds the platform
 * ('tenant', '') catalog with 'free'/'pro'. fromContext()'s 1.x config-overlay
 * behavior is covered separately by ManagedPlanCatalogTest and is untouched here.
 */
final class ScopedPlanCatalogTest extends V2SubscriptionsTestCase
{
    public function testForScopeHasNoConfigOverlay(): void
    {
        // Config defines an 'enterprise' plan, but no DB row exists for it in
        // any scope -- forScope() must not fall back to config.
        $this->setConfig('subscriptions.plans.enterprise.entitlements', ['api.monthly' => 1000000]);
        $this->setConfig('subscriptions.plans.enterprise.provider_price_id', 'configPriceOnly');

        $catalog = PlanCatalog::forScope($this->appContext(), 'tenant', '');

        self::assertSame([], $catalog->entitlementsFor('enterprise'));
        self::assertFalse($catalog->planExists('enterprise'));
        self::assertFalse($catalog->isAssignable('enterprise'));
        self::assertNull($catalog->providerPriceId('enterprise'));
    }

    public function testScopeIsolationSameKeyResolvesIndependently(): void
    {
        $this->seedPlan([
            'plan_key' => 'pro',
            'entitlements' => ['projects.limit' => 7],
            'audience' => 'user',
            'owner_tenant_uuid' => 'workspace-1',
        ]);

        $platform = PlanCatalog::forScope($this->appContext(), 'tenant', '');
        $workspace = PlanCatalog::forScope($this->appContext(), 'user', 'workspace-1');

        self::assertSame(50, $platform->entitlementsFor('pro')['projects.limit']);
        self::assertSame(['projects.limit' => 7], $workspace->entitlementsFor('pro'));
    }

    public function testWorkspaceScopeNeverSeesPlatformRows(): void
    {
        $workspace = PlanCatalog::forScope($this->appContext(), 'user', 'workspace-1');

        self::assertFalse($workspace->planExists('free'));
        self::assertSame([], $workspace->entitlementsFor('free'));
        self::assertFalse($workspace->isAssignable('free'));
    }

    public function testDefaultPlanThrowsOutsidePlatformScope(): void
    {
        $this->expectException(\LogicException::class);

        PlanCatalog::forScope($this->appContext(), 'user', 'workspace-1')->defaultPlan();
    }

    public function testDefaultPlanDoesNotThrowForPlatformScope(): void
    {
        $catalog = PlanCatalog::forScope($this->appContext(), 'tenant', '');

        self::assertSame('free', $catalog->defaultPlan());
    }

    public function testScopedVersionDiffersBetweenScopesAndChangesOnUpdate(): void
    {
        $this->seedPlan([
            'plan_key' => 'starter',
            'audience' => 'user',
            'owner_tenant_uuid' => 'workspace-1',
            'updated_at' => '2026-06-10 10:00:00',
        ]);

        $platformVersion = PlanCatalog::forScope($this->appContext(), 'tenant', '')->version();
        $workspaceVersionBefore = PlanCatalog::forScope($this->appContext(), 'user', 'workspace-1')->version();

        self::assertNotSame($platformVersion, $workspaceVersionBefore);

        $this->connection()->table('subscription_plans')
            ->where('plan_key', '=', 'starter')
            ->where('audience', '=', 'user')
            ->where('owner_tenant_uuid', '=', 'workspace-1')
            ->update(['updated_at' => '2026-06-10 10:00:01']);

        $workspaceVersionAfter = PlanCatalog::forScope($this->appContext(), 'user', 'workspace-1')->version();

        self::assertNotSame($workspaceVersionBefore, $workspaceVersionAfter);
    }

    public function testUuidFirstMethodsResolveWithinScope(): void
    {
        $this->seedPlan([
            'uuid' => 'planworkspro1',
            'plan_key' => 'pro',
            'entitlements' => ['projects.limit' => 9],
            'provider_price_id' => 'workspacePrice1',
            'status' => 'active',
            'audience' => 'user',
            'owner_tenant_uuid' => 'workspace-1',
        ]);

        $workspace = PlanCatalog::forScope($this->appContext(), 'user', 'workspace-1');

        self::assertSame('planworkspro1', $workspace->planUuidForKey('pro'));
        self::assertSame(['projects.limit' => 9], $workspace->entitlementsForUuid('planworkspro1'));
        self::assertTrue($workspace->isAssignableUuid('planworkspro1'));
        self::assertSame('workspacePrice1', $workspace->providerPriceIdForUuid('planworkspro1'));

        self::assertNull($workspace->planUuidForKey('missing-key'));
        self::assertSame([], $workspace->entitlementsForUuid('missing-uuid-x'));
        self::assertFalse($workspace->isAssignableUuid('missing-uuid-x'));
        self::assertNull($workspace->providerPriceIdForUuid('missing-uuid-x'));
    }

    /** @param array<string,mixed> $overrides */
    private function seedPlan(array $overrides = []): void
    {
        $this->connection()->table('subscription_plans')->insert(array_merge([
            'uuid' => Utils::generateNanoID(12),
            'plan_key' => 'pro',
            'display_name' => 'Pro',
            'description' => null,
            'entitlements' => json_encode(['projects.limit' => 100], JSON_THROW_ON_ERROR),
            'provider_price_id' => null,
            'status' => 'active',
            'sort_order' => 10,
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
            'audience' => 'tenant',
            'owner_tenant_uuid' => '',
        ], $this->normalizePlanOverrides($overrides)));
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function normalizePlanOverrides(array $overrides): array
    {
        if (isset($overrides['entitlements']) && is_array($overrides['entitlements'])) {
            $overrides['entitlements'] = json_encode($overrides['entitlements'], JSON_THROW_ON_ERROR);
        }

        return $overrides;
    }
}
