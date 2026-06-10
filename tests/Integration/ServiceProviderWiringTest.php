<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\DefaultEntitlementChecker;
use Glueful\Extensions\Subscriptions\Http\RequireEntitlement;
use Glueful\Extensions\Subscriptions\Listeners\PaymentProviderEventListener;
use Glueful\Extensions\Subscriptions\RateLimiting\EntitlementTierResolver;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
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

    public function testServicesBindReposResolversServiceAndListener(): void
    {
        $services = SubscriptionsServiceProvider::services();

        foreach (
            [
            SubscriptionRepository::class,
            OverrideRepository::class,
            SubscriptionEventRepository::class,
            EffectivePlanResolver::class,
            PaymentProviderEventListener::class,
            ] as $id
        ) {
            self::assertIsArray($services[$id] ?? null, "Missing service definition: {$id}");
            self::assertTrue($services[$id]['shared']);
        }

        self::assertInstanceOf(FactoryDefinition::class, $services[PlanCatalog::class] ?? null);
        self::assertInstanceOf(FactoryDefinition::class, $services[EntitlementResolver::class] ?? null);
        self::assertInstanceOf(FactoryDefinition::class, $services[SubscriptionService::class] ?? null);
    }

    public function testRequireEntitlementCarriesMiddlewareAlias(): void
    {
        $services = SubscriptionsServiceProvider::services();

        $middleware = $services[RequireEntitlement::class] ?? null;
        self::assertIsArray($middleware);
        self::assertContains('require_entitlement', $middleware['alias']);

        self::assertSame(
            ['require_entitlement' => RequireEntitlement::class],
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

        $services = SubscriptionsServiceProvider::services();
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
