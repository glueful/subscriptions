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

/**
 * FLIPPED at the 2.0 activation. Every case here used to assert the 1.x
 * CONFIG-OVERLAY contract -- "a DB row wins, but a missing/non-resolvable DB row
 * falls back to `subscriptions.plans.*`". That overlay is gone: `fromContext()` is
 * now exactly `forScope('tenant', '')`, DB-authoritative, and config plans are
 * SEEDS (import them) rather than a runtime fallback (spec §3, §10).
 *
 * The same-named cases below therefore assert the inverse where the contract
 * inverted, so the diff for this file is itself the proof that the overlay was
 * removed rather than merely bypassed.
 */
final class ManagedPlanCatalogTest extends SubscriptionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This suite owns the catalog: it asserts on resolution of 'pro' in states
        // the harness's platform seed would pre-empt.
        $this->clearPlatformPlans();
    }

    public function testFromContextIsThePlatformScope(): void
    {
        $catalog = PlanCatalog::fromContext($this->appContext());

        self::assertSame('tenant', $catalog->audience());
        self::assertSame('', $catalog->ownerTenantUuid());
        self::assertSame('free', $catalog->defaultPlan());
    }

    public function testEmptySubscriptionPlansTableResolvesNothing(): void
    {
        // Was: "falls back to config". 'pro' IS a config plan -- and now resolves to
        // nothing at all, because no DB row backs it.
        $catalog = PlanCatalog::fromContext($this->appContext());

        self::assertSame([], $catalog->entitlementsFor('pro'));
        self::assertFalse($catalog->planExists('pro'));
        self::assertFalse($catalog->isAssignable('pro'));
        self::assertNull($catalog->planUuidForKey('pro'));
    }

    public function testActiveDbPlanResolvesAndIsAssignable(): void
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

    public function testArchivedDbPlanResolvesButIsNotAssignable(): void
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

    public function testDraftDbPlanExistsButNeitherResolvesNorFallsBackToConfig(): void
    {
        // Was: "falls back to config when config exists". A draft plan is not
        // resolvable, and there is nothing behind it any more.
        $this->seedPlan([
            'plan_key' => 'pro',
            'entitlements' => ['projects.limit' => 999],
            'status' => 'draft',
        ]);

        $catalog = PlanCatalog::fromContext($this->appContext());

        self::assertSame([], $catalog->entitlementsFor('pro'));
        self::assertTrue($catalog->planExists('pro'));
        self::assertFalse($catalog->isAssignable('pro'));
    }

    public function testConfigPlanIsNeverAssignableWithoutADbRow(): void
    {
        // Was: "assignable only when no DB row exists" -- exactly inverted.
        $catalog = PlanCatalog::fromContext($this->appContext());
        self::assertFalse($catalog->isAssignable('free'));

        $this->seedPlan(['plan_key' => 'free', 'status' => 'active']);

        self::assertTrue(PlanCatalog::fromContext($this->appContext())->isAssignable('free'));
    }

    public function testProviderPriceIdComesFromTheDbRowAndNeverFromConfig(): void
    {
        $this->setConfig('subscriptions.plans.pro.provider_price_id', 'configPrice1');

        self::assertNull(PlanCatalog::fromContext($this->appContext())->providerPriceId('pro'));

        $this->seedPlan([
            'plan_key' => 'pro',
            'provider_price_id' => 'dbPrice0001',
            'status' => 'active',
        ]);

        self::assertSame('dbPrice0001', PlanCatalog::fromContext($this->appContext())->providerPriceId('pro'));
    }

    public function testVersionIsScopedAndChangesWhenDbPlanUpdatedAtChanges(): void
    {
        $this->seedPlan([
            'plan_key' => 'pro',
            'updated_at' => '2026-06-10 10:00:00',
        ]);
        $before = PlanCatalog::fromContext($this->appContext())->version();

        self::assertSame('tenant::2026-06-10 10:00:00', $before);

        $this->connection()->table('subscription_plans')
            ->where('plan_key', '=', 'pro')
            ->update(['updated_at' => '2026-06-10 10:00:01']);

        self::assertNotSame($before, PlanCatalog::fromContext($this->appContext())->version());
    }

    public function testVersionCarriesNoConfigHash(): void
    {
        // Was: the version folded a hash of `subscriptions.plans`. With no overlay,
        // config cannot change what resolves, so it must not move the cache key.
        $before = PlanCatalog::fromContext($this->appContext())->version();

        $this->setConfig('subscriptions.plans.pro.entitlements', ['projects.limit' => 4242]);

        self::assertSame($before, PlanCatalog::fromContext($this->appContext())->version());
    }

    public function testNoTableFallbackResolvesNothingRatherThanConfig(): void
    {
        // Was: "behaves as config-only". A missing plan table now means an empty
        // catalog -- the read is guarded so it degrades instead of throwing.
        $catalog = PlanCatalog::fromContext($this->contextWithoutPlanTable());

        self::assertSame([], $catalog->entitlementsFor('pro'));
        self::assertFalse($catalog->planExists('pro'));
        self::assertFalse($catalog->isAssignable('pro'));
        self::assertSame('tenant::none', $catalog->version());
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
