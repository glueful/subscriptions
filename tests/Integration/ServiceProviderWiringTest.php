<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\DefaultEntitlementChecker;
use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Extensions\Subscriptions\Http\RequireEntitlement;
use Glueful\Extensions\Subscriptions\Http\RequirePlanManagementPermission;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\RateLimiting\EntitlementTierResolver;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 7.1 -- provider registrations: the two core-seam overrides (checker over
 * core's Null default, tier resolver over the framework default -- both rely on
 * the container-precedence last-wins fix), factories, middleware alias, commands,
 * and the class_exists-guarded payvia listener.
 */
final class ServiceProviderWiringTest extends SubscriptionsTestCase
{
    public function testServicesBindCheckerAndTierResolverOverCoreSeams(): void
    {
        $services = SubscriptionsServiceProvider::services();

        $checker = $services[\Glueful\Entitlements\Contracts\EntitlementCheckerInterface::class] ?? null;
        self::assertIsArray($checker);
        self::assertSame(DefaultEntitlementChecker::class, $checker['class']);
        self::assertTrue($checker['shared']);

        $tier = $services[\Glueful\Api\RateLimiting\Contracts\TierResolverInterface::class] ?? null;
        self::assertIsArray($tier);
        self::assertSame(EntitlementTierResolver::class, $tier['class']);
        self::assertTrue($tier['shared']);
    }

    public function testServicesBindReposResolversServiceAndBridge(): void
    {
        $services = SubscriptionsServiceProvider::services();

        foreach (
            [
            SubscriptionRepository::class,
            OverrideRepository::class,
            SubscriptionEventRepository::class,
            SubscriptionPlanRepository::class,
            PlanPayloadValidator::class,
            PlanManagementService::class,
            RequirePlanManagementPermission::class,
            PlanController::class,
            EffectivePlanResolver::class,
            PayviaSubscriptionEventBridge::class,
            ] as $id
        ) {
            self::assertIsArray($services[$id] ?? null, "Missing service definition: {$id}");
            self::assertTrue($services[$id]['shared']);
        }

        // The projector is bound behind its interface (shared, autowired).
        $projector = $services[SubscriptionEventProjectorInterface::class] ?? null;
        self::assertIsArray($projector);
        self::assertSame(SubscriptionEventProjector::class, $projector['class']);
        self::assertTrue($projector['shared']);

        foreach ([PlanCatalog::class, EntitlementResolver::class, SubscriptionService::class] as $id) {
            self::assertIsArray($services[$id] ?? null, "Missing factory service definition: {$id}");
            self::assertArrayHasKey('factory', $services[$id]);
            self::assertTrue($services[$id]['shared']);
        }
    }

    public function testServicesLoadThroughRealDefaultServicesLoaderInProductionMode(): void
    {
        $loader = new DefaultServicesLoader();

        $definitions = $loader->load(
            SubscriptionsServiceProvider::services(),
            SubscriptionsServiceProvider::class,
            prod: true
        );

        self::assertInstanceOf(FactoryDefinition::class, $definitions[PlanCatalog::class] ?? null);
        self::assertInstanceOf(FactoryDefinition::class, $definitions[EntitlementResolver::class] ?? null);
        self::assertInstanceOf(FactoryDefinition::class, $definitions[SubscriptionService::class] ?? null);
        self::assertArrayHasKey(\Glueful\Entitlements\Contracts\EntitlementCheckerInterface::class, $definitions);
        self::assertArrayHasKey('require_entitlement', $definitions);
    }

    public function testRealDefaultServicesLoaderRejectsClosureFactoriesInProductionMode(): void
    {
        $loader = new DefaultServicesLoader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('factory closure not allowed in production');

        $loader->load([
            'bad.factory' => [
                'factory' => static fn(): object => new \stdClass(),
                'shared' => true,
            ],
        ], SubscriptionsServiceProvider::class, prod: true);
    }

    public function testRequireEntitlementCarriesMiddlewareAlias(): void
    {
        $services = SubscriptionsServiceProvider::services();

        $middleware = $services[RequireEntitlement::class] ?? null;
        self::assertIsArray($middleware);
        self::assertContains('require_entitlement', $middleware['alias']);

        $planMiddleware = $services[RequirePlanManagementPermission::class] ?? null;
        self::assertIsArray($planMiddleware);
        self::assertContains('subscriptions_plans_manage', $planMiddleware['alias']);

        self::assertSame(
            [
                'require_entitlement' => RequireEntitlement::class,
                'subscriptions_plans_manage' => RequirePlanManagementPermission::class,
            ],
            SubscriptionsServiceProvider::middlewareAliases()
        );
    }

    public function testFactoriesResolveAgainstTheHarnessContainer(): void
    {
        // Give the factories what they pull from the container.
        $this->bind(ApplicationContext::class, $this->appContext());
        $this->bind(SubscriptionRepository::class, new SubscriptionRepository());
        $this->bind(OverrideRepository::class, new OverrideRepository());
        $this->bind(SubscriptionEventRepository::class, new SubscriptionEventRepository());
        $this->bind(EffectivePlanResolver::class, new EffectivePlanResolver());

        $services = (new DefaultServicesLoader())->load(
            SubscriptionsServiceProvider::services(),
            SubscriptionsServiceProvider::class,
            prod: true
        );
        $container = $this->appContext()->getContainer();

        /** @var FactoryDefinition $catalogDef */
        $catalogDef = $services[PlanCatalog::class];
        $catalog = $catalogDef->resolve($container);
        self::assertInstanceOf(PlanCatalog::class, $catalog);
        self::assertSame('free', $catalog->defaultPlan());

        $this->bind(PlanCatalog::class, $catalog);

        // CacheStore is NOT bound in the harness -> the factory must degrade to
        // an uncached resolver (B3) rather than failing.
        /** @var FactoryDefinition $resolverDef */
        $resolverDef = $services[EntitlementResolver::class];
        $resolver = $resolverDef->resolve($container);
        self::assertInstanceOf(EntitlementResolver::class, $resolver);
        self::assertSame(
            ['reports.export' => false, 'projects.limit' => 3, 'team.limit' => 1],
            $resolver->resolveMap($this->appContext(), 'no-such-tenant')
        );

        /** @var FactoryDefinition $serviceDef */
        $serviceDef = $services[SubscriptionService::class];
        $service = $serviceDef->resolve($container);
        self::assertInstanceOf(SubscriptionService::class, $service);
        self::assertNull($service->current('no-such-tenant'));
    }

    public function testBootWithPayviaAbsentRegistersNoListenerAndDoesNotThrow(): void
    {
        // Precondition of this suite: payvia is NOT installed.
        self::assertFalse(class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class));

        $container = $this->appContext()->getContainer();
        self::assertNotNull($container);

        // The harness container THROWS on any unknown id (EventService included),
        // so a clean boot proves the listener registration path was never entered;
        // registerMeta degrades via its own try/catch.
        $provider = new SubscriptionsServiceProvider($container);
        $provider->boot($this->appContext());

        // Commands were discovered and deferred for the console app.
        $deferred = \Glueful\Extensions\ServiceProvider::flushDeferredCommands();
        self::assertContains(\Glueful\Extensions\Subscriptions\Console\ReconcileCommand::class, $deferred);
        self::assertContains(\Glueful\Extensions\Subscriptions\Console\ShowSubscriptionCommand::class, $deferred);
        self::assertContains(\Glueful\Extensions\Subscriptions\Console\SetPlanCommand::class, $deferred);
    }
}
