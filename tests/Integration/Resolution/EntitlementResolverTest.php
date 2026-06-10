<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Resolution;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

final class EntitlementResolverTest extends SubscriptionsTestCase
{
    private const FREE = ['reports.export' => false, 'projects.limit' => 3, 'team.limit' => 1];
    private const PRO = [
        'reports.export' => true,
        'projects.limit' => 50,
        'team.limit' => 20,
        'api.monthly' => 100000,
    ];

    private function catalog(): PlanCatalog
    {
        return new PlanCatalog([
            'default_plan' => 'free',
            'plans' => [
                'free' => ['entitlements' => self::FREE],
                'pro' => ['entitlements' => self::PRO],
            ],
            'grace_days' => 3,
        ]);
    }

    private function resolver(?CacheStore $cache = null, bool $cacheEnabled = false): EntitlementResolver
    {
        return new EntitlementResolver(
            $this->catalog(),
            new SubscriptionRepository(),
            new OverrideRepository(),
            new EffectivePlanResolver(),
            $cache,
            $cacheEnabled,
            300
        );
    }

    /** @param array<string,mixed> $row */
    private function seedOverride(array $row): void
    {
        $this->connection()->table('subscription_overrides')->insert(array_merge([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'expires_at' => null,
        ], $row, ['value' => json_encode($row['value'], JSON_THROW_ON_ERROR)]));
    }

    public function testActiveProTenantResolvesProEntitlements(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);

        self::assertSame(self::PRO, $this->resolver()->resolveMap($this->appContext(), 'tenantA'));
    }

    public function testCanceledProTenantDowngradesToDefaultEntitlements(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'canceled']);

        self::assertSame(self::FREE, $this->resolver()->resolveMap($this->appContext(), 'tenantA'));
    }

    public function testTenantWithNoSubscriptionResolvesDefaultEntitlements(): void
    {
        self::assertSame(self::FREE, $this->resolver()->resolveMap($this->appContext(), 'ghost'));
    }

    public function testActiveOverrideWinsPerKeyAndExpiredOverrideIsIgnored(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);
        $this->seedOverride(['entitlement' => 'projects.limit', 'value' => 999]);
        $this->seedOverride([
            'entitlement' => 'reports.export',
            'value' => false,
            'expires_at' => '2020-01-01 00:00:00',
        ]);

        $map = $this->resolver()->resolveMap($this->appContext(), 'tenantA');

        self::assertSame(999, $map['projects.limit']);
        self::assertTrue($map['reports.export']); // expired override did NOT flip it
    }

    public function testOverrideAddsBrandNewKey(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free', 'status' => 'active']);
        $this->seedOverride(['entitlement' => 'beta.feature', 'value' => true]);

        $map = $this->resolver()->resolveMap($this->appContext(), 'tenantA');

        self::assertTrue($map['beta.feature']);
        self::assertFalse($map['reports.export']);
    }

    public function testCacheKeyComposesTenantCatalogVersionAndUpdatedAts(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);
        $row = (new SubscriptionRepository())->findByTenant($this->appContext(), 'tenantA');
        self::assertIsArray($row);
        $rowStamp = $row['updated_at'] ?? $row['created_at'];

        $expectedKey = 'subscriptions.ent:tenantA:' . $this->catalog()->version() . ':' . $rowStamp . ':0';

        $cache = $this->createMock(CacheStore::class);
        $cache->expects(self::once())
            ->method('remember')
            ->with($expectedKey, self::isInstanceOf(\Closure::class), 300)
            ->willReturnCallback(static fn (string $key, callable $callback, ?int $ttl): mixed => $callback());

        $map = $this->resolver($cache, true)->resolveMap($this->appContext(), 'tenantA');

        self::assertSame(self::PRO, $map);
    }

    public function testResolvesUncachedWhenCacheStoreIsNull(): void
    {
        // B3: CacheStore may be unbound in a zero-infra install -- enabled flag alone
        // must not break resolution.
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);

        $resolver = $this->resolver(null, true);

        self::assertSame(self::PRO, $resolver->resolveMap($this->appContext(), 'tenantA'));
    }
}
