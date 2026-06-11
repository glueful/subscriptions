<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionEventsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionOverridesTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionsTable;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;

final class ManagedPlanCatalogTest extends SubscriptionsTestCase
{
    public function testEmptySubscriptionPlansTableFallsBackToConfig(): void
    {
        $catalog = PlanCatalog::fromContext($this->appContext());

        self::assertSame(50, $catalog->entitlementsFor('pro')['projects.limit']);
        self::assertTrue($catalog->isAssignable('pro'));
    }

    public function testActiveDbPlanOverridesConfigPlan(): void
    {
        $this->seedPlan([
            'plan_key' => 'pro',
            'entitlements' => ['projects.limit' => 500],
            'status' => 'active',
        ]);

        $catalog = PlanCatalog::fromContext($this->appContext());

        self::assertSame(['projects.limit' => 500], $catalog->entitlementsFor('pro'));
        self::assertTrue($catalog->isAssignable('pro'));
    }

    public function testArchivedDbPlanOverridesConfigAndResolvesButIsNotAssignable(): void
    {
        $this->seedPlan([
            'plan_key' => 'pro',
            'entitlements' => ['projects.limit' => 250],
            'status' => 'archived',
        ]);

        $catalog = PlanCatalog::fromContext($this->appContext());

        self::assertSame(['projects.limit' => 250], $catalog->entitlementsFor('pro'));
        self::assertFalse($catalog->isAssignable('pro'));
    }

    public function testDraftDbPlanFallsBackToConfigWhenConfigExists(): void
    {
        $this->seedPlan([
            'plan_key' => 'pro',
            'entitlements' => ['projects.limit' => 999],
            'status' => 'draft',
        ]);

        $catalog = PlanCatalog::fromContext($this->appContext());

        self::assertSame(50, $catalog->entitlementsFor('pro')['projects.limit']);
        self::assertFalse($catalog->isAssignable('pro'));
    }

    public function testDbOnlyDraftPlanResolvesToEmptyMap(): void
    {
        $this->seedPlan([
            'plan_key' => 'future',
            'entitlements' => ['projects.limit' => 999],
            'status' => 'draft',
        ]);

        $catalog = PlanCatalog::fromContext($this->appContext());

        self::assertSame([], $catalog->entitlementsFor('future'));
        self::assertTrue($catalog->planExists('future'));
        self::assertFalse($catalog->isAssignable('future'));
    }

    public function testConfigPlanIsAssignableOnlyWhenNoDbRowExists(): void
    {
        $catalog = PlanCatalog::fromContext($this->appContext());
        self::assertTrue($catalog->isAssignable('free'));

        $this->seedPlan([
            'plan_key' => 'free',
            'status' => 'archived',
        ]);

        self::assertFalse(PlanCatalog::fromContext($this->appContext())->isAssignable('free'));
    }

    public function testPricedPlanUuidPrefersResolvableDbPlanOverConfig(): void
    {
        $this->setConfig('subscriptions.plans.pro.payvia_priced_plan', 'configPrice1');
        $this->seedPlan([
            'plan_key' => 'pro',
            'payvia_priced_plan_uuid' => 'dbPrice0001',
            'status' => 'active',
        ]);

        self::assertSame('dbPrice0001', PlanCatalog::fromContext($this->appContext())->pricedPlanUuid('pro'));
    }

    public function testVersionChangesWhenDbPlanUpdatedAtChanges(): void
    {
        $this->seedPlan([
            'plan_key' => 'pro',
            'updated_at' => '2026-06-10 10:00:00',
        ]);
        $catalog = PlanCatalog::fromContext($this->appContext());
        $before = $catalog->version();

        $this->connection()->table('subscription_plans')
            ->where('plan_key', '=', 'pro')
            ->update(['updated_at' => '2026-06-10 10:00:01']);

        self::assertNotSame($before, PlanCatalog::fromContext($this->appContext())->version());
    }

    public function testNoTableFallbackBehavesAsConfigOnly(): void
    {
        $context = $this->contextWithoutPlanTable();
        $catalog = PlanCatalog::fromContext($context);

        self::assertSame(50, $catalog->entitlementsFor('pro')['projects.limit']);
        self::assertTrue($catalog->planExists('pro'));
        self::assertTrue($catalog->isAssignable('pro'));
        self::assertSame('none', substr($catalog->version(), -4));
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
            'payvia_priced_plan_uuid' => null,
            'status' => 'active',
            'sort_order' => 10,
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
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

    private function contextWithoutPlanTable(): ApplicationContext
    {
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        $schema = $connection->getSchemaBuilder();
        (new CreateSubscriptionsTable())->up($schema);
        (new CreateSubscriptionOverridesTable())->up($schema);
        (new CreateSubscriptionEventsTable())->up($schema);

        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('subscriptions', require __DIR__ . '/../../../config/subscriptions.php');

        return $context;
    }
}
