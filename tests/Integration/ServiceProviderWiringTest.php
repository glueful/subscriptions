<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Autowire\AutowireDefinition;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\DefaultEntitlementChecker;
use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Extensions\Subscriptions\Http\RequireEntitlement;
use Glueful\Extensions\Subscriptions\Http\RequireMemberEntitlement;
use Glueful\Extensions\Subscriptions\Http\RequirePlanManagementPermission;
use Glueful\Extensions\Subscriptions\Lifecycle\SubscriptionSubjectDataPurger;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\RateLimiting\EntitlementTierResolver;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\Resolution\MemberEntitlementResolver;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider;
use Glueful\Extensions\Subscriptions\Tests\Support\PermissiveSubjectResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\RecordingTenantTableRegistry;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 7.1 -- provider registrations: the two core-seam overrides (checker over
 * core's Null default, tier resolver over the framework default -- both rely on
 * the container-precedence last-wins fix), factories, middleware alias, commands,
 * and the class_exists-guarded payvia bridge.
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

        // The projector is bound behind its interface via an explicit factory
        // (Task 10): it constructs ProviderEventReceiptRepository directly since
        // that repository isn't registered as a standalone service yet (Task 14).
        foreach (
            [
            PlanCatalog::class,
            EntitlementResolver::class,
            SubscriptionService::class,
            SubscriptionEventProjectorInterface::class,
            ] as $id
        ) {
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
        self::assertInstanceOf(
            FactoryDefinition::class,
            $definitions[SubscriptionEventProjectorInterface::class] ?? null
        );
        self::assertInstanceOf(FactoryDefinition::class, $definitions[SubscriptionService::class] ?? null);
        self::assertArrayHasKey(\Glueful\Entitlements\Contracts\EntitlementCheckerInterface::class, $definitions);
        self::assertArrayHasKey('require_entitlement', $definitions);

        // Task 14: MemberEntitlementResolver's ctor-injected catalog is pinned to
        // ONE workspace (Task 11's assertCatalogScopeMatches guard) -- a shared
        // singleton would freeze that scope to whichever tenant happened to be
        // current the first time the container built it. It MUST load as a
        // non-shared factory, and RequireMemberEntitlement (which injects it via
        // its own constructor) must be non-shared for the same reason.
        self::assertInstanceOf(FactoryDefinition::class, $definitions[MemberEntitlementResolver::class] ?? null);
        self::assertFalse($definitions[MemberEntitlementResolver::class]->isShared());

        self::assertInstanceOf(AutowireDefinition::class, $definitions[RequireMemberEntitlement::class] ?? null);
        self::assertFalse($definitions[RequireMemberEntitlement::class]->isShared());
        self::assertArrayHasKey('require_member_entitlement', $definitions);

        self::assertInstanceOf(
            AutowireDefinition::class,
            $definitions[ProviderEventReceiptRepository::class] ?? null
        );
        self::assertTrue($definitions[ProviderEventReceiptRepository::class]->isShared());

        self::assertInstanceOf(AutowireDefinition::class, $definitions[SubscriptionSubjectDataPurger::class] ?? null);
        self::assertTrue($definitions[SubscriptionSubjectDataPurger::class]->isShared());
    }

    /**
     * Task 14 -- spec §4/§10: the shipped default rejects every user subject, so
     * memberships stay inert until a host REBINDS this interface to a resolver
     * that can vouch for users. Binding it is the enablement switch; there is no
     * config flag.
     */
    public function testServicesBindSubjectResolverInterfaceToDefaultOverridableByHosts(): void
    {
        $services = SubscriptionsServiceProvider::services();

        $def = $services[SubjectResolverInterface::class] ?? null;
        self::assertIsArray($def, 'Missing SubjectResolverInterface service definition');
        self::assertSame(DefaultSubjectResolver::class, $def['class']);
        self::assertTrue($def['shared']);
    }

    /**
     * Task 14: ProviderEventReceiptRepository and SubscriptionSubjectDataPurger
     * have no per-request-scoped state (unlike MemberEntitlementResolver), so
     * they are ordinary shared, autowired services.
     */
    public function testServicesRegisterProviderEventReceiptRepositoryAndSubjectDataPurgerAsSharedAutowired(): void
    {
        $services = SubscriptionsServiceProvider::services();

        foreach ([ProviderEventReceiptRepository::class, SubscriptionSubjectDataPurger::class] as $id) {
            self::assertIsArray($services[$id] ?? null, "Missing service definition: {$id}");
            self::assertTrue($services[$id]['shared'], "{$id} should be a shared service");
            self::assertTrue($services[$id]['autowire'] ?? false, "{$id} should be autowired");
        }
    }

    /**
     * Task 14 / Task 11's contract: MemberEntitlementResolver's constructor is
     * handed a PlanCatalog already scoped to ONE workspace
     * (`forScope($context, 'user', $tenantUuid)`); assertCatalogScopeMatches()
     * throws if a caller ever reuses an instance built for a different tenant. A
     * naive shared singleton registration would silently violate that contract
     * the moment two different workspaces' requests hit the same process, so
     * this MUST be a non-shared factory that resolves the current tenant (via
     * SubjectResolverInterface) fresh on every container resolution.
     */
    public function testMemberEntitlementResolverIsRegisteredAsANonSharedFactory(): void
    {
        $services = SubscriptionsServiceProvider::services();

        $def = $services[MemberEntitlementResolver::class] ?? null;
        self::assertIsArray($def, 'Missing MemberEntitlementResolver service definition');
        self::assertArrayHasKey('factory', $def);
        self::assertFalse(
            $def['shared'] ?? true,
            'MemberEntitlementResolver must NOT be a shared singleton -- see Task 11\'s '
                . 'assertCatalogScopeMatches() guard.'
        );
    }

    /**
     * Task 14: RequireMemberEntitlement's own constructor injects a
     * MemberEntitlementResolver, so it inherits the same non-shared requirement
     * -- a cached RequireMemberEntitlement would freeze its resolver (and thus
     * its workspace scope) to whichever tenant was current the first time the
     * container built it.
     */
    public function testRequireMemberEntitlementIsRegisteredAsNonSharedWithItsMiddlewareAlias(): void
    {
        $services = SubscriptionsServiceProvider::services();

        $def = $services[RequireMemberEntitlement::class] ?? null;
        self::assertIsArray($def, 'Missing RequireMemberEntitlement service definition');
        self::assertSame(RequireMemberEntitlement::class, $def['class']);
        self::assertFalse(
            $def['shared'] ?? true,
            'RequireMemberEntitlement must not be shared, mirroring MemberEntitlementResolver.'
        );
        self::assertContains('require_member_entitlement', $def['alias']);

        self::assertSame(
            [
                'require_entitlement' => RequireEntitlement::class,
                'subscriptions_plans_manage' => RequirePlanManagementPermission::class,
                'require_member_entitlement' => RequireMemberEntitlement::class,
            ],
            SubscriptionsServiceProvider::middlewareAliases()
        );
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

        // The full three-entry map (including require_member_entitlement) is
        // asserted in testRequireMemberEntitlementIsRegisteredAsNonSharedWithItsMiddlewareAlias().
    }

    public function testFactoriesResolveAgainstTheHarnessContainer(): void
    {
        // Give the factories what they pull from the container.
        $this->bind(ApplicationContext::class, $this->appContext());
        $this->bind(SubjectResolverInterface::class, new DefaultSubjectResolver());
        $this->bind(SubscriptionRepository::class, new SubscriptionRepository());
        $this->bind(OverrideRepository::class, new OverrideRepository());
        $this->bind(SubscriptionEventRepository::class, new SubscriptionEventRepository());
        $this->bind(EffectivePlanResolver::class, new EffectivePlanResolver());
        // ProviderEventReceiptRepository is now a standalone registered service
        // (Task 14) that the projector's factory pulls from the container
        // instead of constructing directly.
        $this->bind(ProviderEventReceiptRepository::class, new ProviderEventReceiptRepository());

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

        /** @var FactoryDefinition $projectorDef */
        $projectorDef = $services[SubscriptionEventProjectorInterface::class];
        $projector = $projectorDef->resolve($container);
        self::assertInstanceOf(
            \Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector::class,
            $projector
        );

        /** @var AutowireDefinition $purgerDef */
        $purgerDef = $services[SubscriptionSubjectDataPurger::class];
        self::assertInstanceOf(SubscriptionSubjectDataPurger::class, $purgerDef->resolve($container));

        /** @var AutowireDefinition $receiptsDef */
        $receiptsDef = $services[ProviderEventReceiptRepository::class];
        self::assertInstanceOf(ProviderEventReceiptRepository::class, $receiptsDef->resolve($container));

        // MemberEntitlementResolver's factory resolves the CURRENT tenant via
        // SubjectResolverInterface and scopes its catalog to it -- rebind the
        // resolver to a fake that names a real workspace, and each resolution
        // must produce a FRESH instance (never shared) that is safely usable for
        // that exact tenant (Task 11's assertCatalogScopeMatches guard would
        // throw otherwise).
        $this->bind(SubjectResolverInterface::class, new PermissiveSubjectResolver('tenantA', 'userA'));

        /** @var FactoryDefinition $memberResolverDef */
        $memberResolverDef = $services[MemberEntitlementResolver::class];
        $memberResolverA = $memberResolverDef->resolve($container);
        $memberResolverB = $memberResolverDef->resolve($container);
        self::assertInstanceOf(MemberEntitlementResolver::class, $memberResolverA);
        self::assertNotSame(
            $memberResolverA,
            $memberResolverB,
            'MemberEntitlementResolver resolutions must never be reused across tenants'
        );
        self::assertSame([], $memberResolverA->resolveMap($this->appContext(), 'tenantA', 'userA'));

        $this->bind(MemberEntitlementResolver::class, $memberResolverA);

        /** @var AutowireDefinition $middlewareDef */
        $middlewareDef = $services[RequireMemberEntitlement::class];
        $middleware = $middlewareDef->resolve($container);
        self::assertInstanceOf(RequireMemberEntitlement::class, $middleware);

        $response = $middleware->handle(
            Request::create('/content'),
            fn (Request $request) => new \Glueful\Http\Response(['ok' => true]),
            'content.premium'
        );
        self::assertSame(\Glueful\Http\Response::HTTP_FORBIDDEN, $response->getStatusCode());
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

    /**
     * Task 12 -- spec §9: `subscriptions`, `subscription_overrides`,
     * `subscription_events` are registered as ordinary tenant-owned tables, outside
     * any feature gate, WHENEVER a `TenantTableRegistry` is bound. `subscription_plans`
     * (mixed platform/workspace ownership under a differently-named owner column) and
     * `subscription_provider_event_receipts` (rejected candidates may carry no valid
     * tenant) are deliberately never registered.
     */
    public function testBootRegistersExactlyTheThreeConventionalTenantTablesWhenARegistryIsBound(): void
    {
        $registry = new RecordingTenantTableRegistry();
        $this->bind(TenantTableRegistry::class, $registry);

        $provider = new SubscriptionsServiceProvider($this->appContext()->getContainer());
        $provider->boot($this->appContext());

        self::assertSame(
            ['subscriptions', 'subscription_overrides', 'subscription_events'],
            $registry->registered()
        );
    }

    /**
     * Mirrors testBootWithPayviaAbsentRegistersNoListenerAndDoesNotThrow(): the harness
     * container throws on any unknown id, so a clean boot() with NO TenantTableRegistry
     * bound proves the registration path is skipped via has(), never reaching get().
     */
    public function testBootDoesNotThrowWhenNoTenantTableRegistryIsBound(): void
    {
        $provider = new SubscriptionsServiceProvider($this->appContext()->getContainer());
        $provider->boot($this->appContext());

        self::assertTrue(true); // reaching here means boot() degraded gracefully
    }
}
