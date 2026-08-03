<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Catalog;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use PHPUnit\Framework\TestCase;

/**
 * FLIPPED at the 2.0 activation: a context-less PlanCatalog is now a
 * CONFIG-ACCESSOR only. `default_plan` and `grace_days` still come from config
 * (spec §10 keeps them there), but plan RESOLUTION is DB-authoritative -- with no
 * context and no repository there is nothing to resolve, and the config's
 * `plans` array is a seed list, not a fallback catalog.
 *
 * The DB-backed resolution contract is covered by ManagedPlanCatalogTest
 * (platform scope) and ScopedPlanCatalogTest (workspace scopes).
 */
final class PlanCatalogTest extends TestCase
{
    /** @return array<string,mixed> */
    private function catalogConfig(): array
    {
        return [
            'default_plan' => 'free',
            'plans' => [
                'free' => [
                    'entitlements' => [
                        'reports.export' => false,
                        'projects.limit' => 3,
                        'team.limit' => 1,
                    ],
                ],
                'pro' => [
                    'provider_price_id' => null,
                    'entitlements' => [
                        'reports.export' => true,
                        'projects.limit' => 50,
                        'team.limit' => 20,
                        'api.monthly' => 100000,
                    ],
                ],
            ],
            'grace_days' => 3,
            'cache' => ['enabled' => true, 'ttl' => 300],
        ];
    }

    public function testDefaultPlan(): void
    {
        $catalog = new PlanCatalog($this->catalogConfig());

        self::assertSame('free', $catalog->defaultPlan());
    }

    public function testDefaultsToThePlatformScope(): void
    {
        $catalog = new PlanCatalog($this->catalogConfig());

        self::assertSame('tenant', $catalog->audience());
        self::assertSame('', $catalog->ownerTenantUuid());
    }

    public function testDefaultPlanIsUndefinedOutsideThePlatformScope(): void
    {
        $catalog = new PlanCatalog($this->catalogConfig(), null, null, 'user', 'workspace-1');

        $this->expectException(\LogicException::class);
        $catalog->defaultPlan();
    }

    public function testConfigPlansAreSeedsAndResolveToNothingWithoutADatabase(): void
    {
        // Was: config plans resolved directly out of the array below.
        $catalog = new PlanCatalog($this->catalogConfig());

        self::assertSame([], $catalog->entitlementsFor('free'));
        self::assertSame([], $catalog->entitlementsFor('pro'));
        self::assertFalse($catalog->planExists('pro'));
        self::assertFalse($catalog->isAssignable('pro'));
        self::assertNull($catalog->providerPriceId('pro'));
        self::assertNull($catalog->planUuidForKey('pro'));
    }

    public function testEntitlementsForMissingPlanIsEmpty(): void
    {
        $catalog = new PlanCatalog($this->catalogConfig());

        self::assertSame([], $catalog->entitlementsFor('missing'));
    }

    public function testGraceDays(): void
    {
        $catalog = new PlanCatalog($this->catalogConfig());

        self::assertSame(3, $catalog->graceDays());
    }

    public function testVersionIdentifiesTheScopeAndIgnoresConfigPlans(): void
    {
        // Was: the version hashed `plans`, so editing config moved it. It is now a
        // scope + DB-freshness signature; config cannot influence resolution, so it
        // must not influence the cache key either.
        $config = $this->catalogConfig();

        self::assertSame('tenant::none', (new PlanCatalog($config))->version());

        $changed = $config;
        $changed['plans']['pro']['entitlements']['projects.limit'] = 51;

        self::assertSame('tenant::none', (new PlanCatalog($changed))->version());
        self::assertSame(
            'user:workspace-1:none',
            (new PlanCatalog($config, null, null, 'user', 'workspace-1'))->version()
        );
    }
}
