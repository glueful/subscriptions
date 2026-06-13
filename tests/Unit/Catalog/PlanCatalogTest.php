<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Catalog;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use PHPUnit\Framework\TestCase;

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

    public function testEntitlementsForKnownPlans(): void
    {
        $catalog = new PlanCatalog($this->catalogConfig());

        self::assertSame(
            ['reports.export' => false, 'projects.limit' => 3, 'team.limit' => 1],
            $catalog->entitlementsFor('free')
        );
        self::assertSame(100000, $catalog->entitlementsFor('pro')['api.monthly']);
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

    public function testVersionIsStableForIdenticalConfigAndChangesWithPlans(): void
    {
        $config = $this->catalogConfig();

        $versionA = (new PlanCatalog($config))->version();
        $versionB = (new PlanCatalog($config))->version();

        self::assertNotSame('', $versionA);
        self::assertSame($versionA, $versionB);

        $changed = $config;
        $changed['plans']['pro']['entitlements']['projects.limit'] = 51;

        self::assertNotSame($versionA, (new PlanCatalog($changed))->version());
    }
}
