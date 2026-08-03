<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Autowire\AutowireDefinition;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Bridge\StrictLaneRegistration;
use Glueful\Extensions\Subscriptions\Bridge\StrictPayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;
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
use Glueful\Extensions\Subscriptions\Resolution\MemberEntitlementResolverFactory;
use Glueful\Extensions\Subscriptions\Subject;
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
        // (Task 10); since Task 14 it resolves ProviderEventReceiptRepository
        // from the container rather than constructing it directly.
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

        // Task 14 (fixed post-review): MemberEntitlementResolverFactory is
        // STATELESS -- it builds a workspace-scoped MemberEntitlementResolver
        // on demand from a caller-supplied tenantUuid (never from
        // SubjectResolverInterface::currentTenant() read at DI-resolution
        // time, which -- because Router::executeWithMiddleware() resolves
        // every middleware from the container in ONE pre-pass before any
        // handle() runs -- could bake a stale/empty scope). Being stateless,
        // it and RequireMemberEntitlement (which now only injects this
        // factory, not a pre-scoped resolver) are both ordinary SHARED
        // services, exactly like require_entitlement's wiring.
        self::assertInstanceOf(
            FactoryDefinition::class,
            $definitions[MemberEntitlementResolverFactory::class] ?? null
        );
        self::assertTrue($definitions[MemberEntitlementResolverFactory::class]->isShared());

        self::assertInstanceOf(AutowireDefinition::class, $definitions[RequireMemberEntitlement::class] ?? null);
        self::assertTrue($definitions[RequireMemberEntitlement::class]->isShared());
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
     * Task 14 (fixed post-review, Critical): MemberEntitlementResolverFactory
     * builds a workspace-scoped MemberEntitlementResolver ON DEMAND from a
     * caller-supplied tenantUuid -- it holds no per-tenant state of its own
     * (only constant-for-the-app-lifetime deps: repositories, the effective
     * plan resolver, the optional cache), so it is safe to be an ordinary
     * SHARED factory. The earlier design read
     * SubjectResolverInterface::currentTenant() INSIDE the factory at
     * container-resolution time, which Router::executeWithMiddleware()'s
     * resolve-every-middleware-before-any-handle()-runs pre-pass could catch
     * before an earlier route-level tenancy middleware had set the tenant --
     * baking a stale/empty scope that then threw once handle() reran
     * currentTenant() and called resolveMap(). See
     * MemberEntitlementResolverFactory's own docblock.
     */
    public function testMemberEntitlementResolverFactoryIsRegisteredAsASharedFactory(): void
    {
        $services = SubscriptionsServiceProvider::services();

        $def = $services[MemberEntitlementResolverFactory::class] ?? null;
        self::assertIsArray($def, 'Missing MemberEntitlementResolverFactory service definition');
        self::assertArrayHasKey('factory', $def);
        self::assertTrue(
            $def['shared'] ?? false,
            'MemberEntitlementResolverFactory is stateless and safe to share.'
        );
    }

    /**
     * Task 14 (fixed post-review): RequireMemberEntitlement now injects the
     * stateless MemberEntitlementResolverFactory (not a pre-scoped resolver),
     * and only asks it to build a workspace-scoped resolver INSIDE handle(),
     * after its own currentTenant() read -- so, exactly like
     * RequireEntitlement, it holds no tenant-scoped state and is safe to
     * share.
     */
    public function testRequireMemberEntitlementIsRegisteredAsSharedWithItsMiddlewareAlias(): void
    {
        $services = SubscriptionsServiceProvider::services();

        $def = $services[RequireMemberEntitlement::class] ?? null;
        self::assertIsArray($def, 'Missing RequireMemberEntitlement service definition');
        self::assertSame(RequireMemberEntitlement::class, $def['class']);
        self::assertTrue($def['shared'] ?? false, 'RequireMemberEntitlement holds no tenant-scoped state.');
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

        // MemberEntitlementResolverFactory is stateless -- resolving it does NOT
        // touch SubjectResolverInterface at all, so binding it once and reusing
        // it for RequireMemberEntitlement is exactly the intended shape.
        $this->bind(SubjectResolverInterface::class, new PermissiveSubjectResolver('tenantA', 'userA'));

        /** @var FactoryDefinition $memberResolverFactoryDef */
        $memberResolverFactoryDef = $services[MemberEntitlementResolverFactory::class];
        $memberResolverFactory = $memberResolverFactoryDef->resolve($container);
        self::assertInstanceOf(MemberEntitlementResolverFactory::class, $memberResolverFactory);

        $this->bind(MemberEntitlementResolverFactory::class, $memberResolverFactory);

        // forWorkspace() builds a fresh, correctly-scoped resolver per call --
        // exercised directly here for the "no membership row" empty-map case.
        $memberResolver = $memberResolverFactory->forWorkspace($this->appContext(), 'tenantA');
        self::assertSame([], $memberResolver->resolveMap($this->appContext(), 'tenantA', 'userA'));

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

    /**
     * Coordinator-flagged Critical, reproduced and closed: simulates the exact
     * pre-pass timing bug. Router::executeWithMiddleware() resolves EVERY
     * middleware from the container in one pass BEFORE any handle() runs, so
     * on a stack like ['tenant', 'require_member_entitlement:x'] the
     * container builds require_member_entitlement's dependencies while
     * SubjectResolverInterface::currentTenant() still reads null (the
     * route-level tenancy middleware ordered earlier has not run its own
     * handle() yet). Only AFTER that construction does the tenant context
     * become available (the earlier middleware's handle() finally runs),
     * before require_member_entitlement's OWN handle() executes.
     *
     * The old design baked SubjectResolverInterface::currentTenant() into the
     * MemberEntitlementResolver factory itself, so this exact sequence baked
     * a catalog scoped to `('user', '')`, and handle() calling resolveMap()
     * against it -- with the NOW-correct non-empty tenantUuid -- tripped Task
     * 11's assertCatalogScopeMatches() guard: an uncaught InvalidArgumentException
     * (a 500), not the intended fail-closed 403 or a correct allow/deny.
     *
     * The fix (MemberEntitlementResolverFactory) never reads currentTenant()
     * at construction time at all -- this test proves the fixed middleware
     * resolves the CORRECT workspace scope regardless of when the tenant
     * context becomes available relative to DI construction, and never
     * throws.
     */
    public function testMiddlewareNeverBakesAStaleCatalogScopeWhenTenantContextArrivesAfterConstruction(): void
    {
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => 'planskewpro01',
            'plan_key' => 'skew-pro',
            'display_name' => 'Skew Pro',
            'entitlements' => json_encode(['content.premium' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => 'tenantSkew',
        ]);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantSkew',
            'subject_type' => 'user',
            'subject_uuid' => 'userSkew',
            'plan_key' => 'skew-pro',
            'status' => 'active',
        ]);

        // A mutable fake: currentTenant()/currentUser() can be changed AFTER
        // the container has already resolved services against it, mirroring
        // the pipeline's pre-pass-then-handle() timing.
        $subjects = new class implements SubjectResolverInterface {
            public ?string $tenant = null;
            public ?string $user = null;

            public function currentTenant(ApplicationContext $context): ?string
            {
                return $this->tenant;
            }

            public function currentUser(ApplicationContext $context): ?string
            {
                return $this->user;
            }

            public function validate(ApplicationContext $context, Subject $subject): bool
            {
                return true;
            }
        };

        $this->bind(ApplicationContext::class, $this->appContext());
        $this->bind(SubjectResolverInterface::class, $subjects);
        $this->bind(SubscriptionRepository::class, new SubscriptionRepository());
        $this->bind(OverrideRepository::class, new OverrideRepository());
        $this->bind(EffectivePlanResolver::class, new EffectivePlanResolver());

        $services = (new DefaultServicesLoader())->load(
            SubscriptionsServiceProvider::services(),
            SubscriptionsServiceProvider::class,
            prod: true
        );
        $container = $this->appContext()->getContainer();

        // Resolve (and thus construct) the factory AND the middleware while
        // currentTenant() === null -- the pre-pass moment.
        /** @var FactoryDefinition $factoryDef */
        $factoryDef = $services[MemberEntitlementResolverFactory::class];
        $resolverFactory = $factoryDef->resolve($container);
        $this->bind(MemberEntitlementResolverFactory::class, $resolverFactory);

        /** @var AutowireDefinition $middlewareDef */
        $middlewareDef = $services[RequireMemberEntitlement::class];
        $middleware = $middlewareDef->resolve($container);

        // NOW the tenant/user context "arrives" -- simulating an earlier
        // route-level tenancy middleware's handle() running before
        // require_member_entitlement's.
        $subjects->tenant = 'tenantSkew';
        $subjects->user = 'userSkew';

        $response = $middleware->handle(
            Request::create('/content'),
            fn (Request $request) => new \Glueful\Http\Response(['ok' => true]),
            'content.premium'
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            'must resolve the CORRECT workspace scope built inside handle(), never throw the scope guard'
        );
    }

    public function testBootWithPayviaPresentDegradesGracefullyWhenEventServiceIsUnbound(): void
    {
        // Since Task 5 (strict payvia lane), glueful/payvia is a real require-dev
        // fixture, so this class IS autoloadable here -- the listener-registration
        // branch in boot() below is now actually exercised, not dead code.
        self::assertTrue(class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class));

        $container = $this->appContext()->getContainer();
        self::assertNotNull($container);

        // The harness container still THROWS on any unknown id (EventService
        // included); boot()'s own try/catch must swallow that and keep going --
        // bootEnv() defaults to 'production' with no APP_ENV set, so it never
        // rethrows. registerMeta degrades via its own try/catch the same way.
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

    /**
     * Task 7 -- spec §4: the strict-adapter definition is present in
     * serviceDefinitionsForMode()'s output ONLY in strict mode -- ABSENT
     * (not merely present-but-unused) from the bus/none maps. Driven
     * directly with each explicit mode since payvia ^2.4 being a permanent
     * require-dev fixture (Task 5) makes `interface_exists()` always true
     * in-process, so the real `services()` entry point can never itself
     * exercise the bus/none branches. `$payviaRuntimePresent` is passed
     * explicitly throughout (fix round, review finding #2): the method is
     * now PURE and no longer probes `class_exists()` itself.
     */
    public function testServiceDefinitionsForModeIncludesTheStrictAdapterOnlyInStrictMode(): void
    {
        $strict = SubscriptionsServiceProvider::serviceDefinitionsForMode(StrictLaneRegistration::STRICT, true);
        self::assertIsArray($strict[StrictPayviaSubscriptionEventBridge::class] ?? null);
        self::assertTrue($strict[StrictPayviaSubscriptionEventBridge::class]['shared']);
        self::assertTrue($strict[StrictPayviaSubscriptionEventBridge::class]['autowire']);
        self::assertSame(
            [StrictPaymentEventListener::CONTAINER_TAG],
            $strict[StrictPayviaSubscriptionEventBridge::class]['tags']
        );

        foreach ([StrictLaneRegistration::BUS, StrictLaneRegistration::NONE] as $mode) {
            $defs = SubscriptionsServiceProvider::serviceDefinitionsForMode($mode, true);
            self::assertArrayNotHasKey(
                StrictPayviaSubscriptionEventBridge::class,
                $defs,
                "mode '{$mode}' must not define the strict adapter"
            );
            // The ordinary bus adapter stays registered regardless of mode -- the
            // lazy '@serviceId' listener needs it resolvable whenever it's wired.
            self::assertIsArray($defs[PayviaSubscriptionEventBridge::class] ?? null);
        }
    }

    /**
     * Task 7 fix round (review finding #2): serviceDefinitionsForMode() takes
     * $payviaRuntimePresent as an explicit, independent input rather than
     * probing class_exists(GatewaySubscriptionService) itself -- so the
     * ProviderStatePullerInterface binding is toggleable independently of
     * $mode. This is also what makes `bus` genuinely != `none`: bus models
     * payvia present (just pre-strict-contract), none models payvia
     * genuinely absent.
     */
    public function testServiceDefinitionsForModeBindsProviderStatePullerOnlyWhenPayviaRuntimePresentIsTrue(): void
    {
        foreach ([StrictLaneRegistration::STRICT, StrictLaneRegistration::BUS, StrictLaneRegistration::NONE] as $mode) {
            $withPayvia = SubscriptionsServiceProvider::serviceDefinitionsForMode($mode, true);
            self::assertArrayHasKey(
                ProviderStatePullerInterface::class,
                $withPayvia,
                "mode '{$mode}' with payvia present must bind ProviderStatePullerInterface"
            );

            $withoutPayvia = SubscriptionsServiceProvider::serviceDefinitionsForMode($mode, false);
            self::assertArrayNotHasKey(
                ProviderStatePullerInterface::class,
                $withoutPayvia,
                "mode '{$mode}' with payvia absent must NOT bind ProviderStatePullerInterface"
            );
        }
    }

    /**
     * Task 7: services() (the real, no-arg entry point the framework calls)
     * delegates to serviceDefinitionsForMode() with the real runtime mode.
     * Since payvia ^2.4 is a permanent require-dev fixture, that mode is
     * always STRICT here -- this is a real, non-faked assertion about this
     * repo's actual dev environment.
     */
    public function testRealServicesEntryPointIncludesTheStrictAdapterInThisDevEnvironment(): void
    {
        $services = SubscriptionsServiceProvider::services();

        self::assertIsArray($services[StrictPayviaSubscriptionEventBridge::class] ?? null);
    }

    /**
     * Task 7: tagsForMode() publishes the strict adapter under payvia's
     * StrictPaymentEventListener::CONTAINER_TAG ONLY in strict mode; bus/none
     * publish no tags at all.
     */
    public function testTagsForModePublishesTheStrictAdapterUnderContainerTagOnlyInStrictMode(): void
    {
        self::assertSame(
            [StrictPaymentEventListener::CONTAINER_TAG => [StrictPayviaSubscriptionEventBridge::class]],
            SubscriptionsServiceProvider::tagsForMode(StrictLaneRegistration::STRICT)
        );

        foreach ([StrictLaneRegistration::BUS, StrictLaneRegistration::NONE] as $mode) {
            self::assertSame(
                [],
                SubscriptionsServiceProvider::tagsForMode($mode),
                "mode '{$mode}' must publish no tags"
            );
        }
    }

    /**
     * Task 7: tags() (the real, no-arg entry point ContainerFactory::applyProviderTags()
     * calls) mirrors the real-environment services() assertion above -- always
     * strict in this repo, since payvia ^2.4 is a permanent require-dev fixture.
     */
    public function testRealTagsEntryPointPublishesTheStrictAdapterInThisDevEnvironment(): void
    {
        self::assertSame(
            [StrictPaymentEventListener::CONTAINER_TAG => [StrictPayviaSubscriptionEventBridge::class]],
            SubscriptionsServiceProvider::tags()
        );
    }

    /**
     * Task 7: the pure, mode-parameterized companion to boot()'s S7 branch --
     * the degraded bus fallback listener is wired ONLY in bus mode.
     */
    public function testShouldRegisterEventBusFallbackIsTrueOnlyInBusMode(): void
    {
        self::assertTrue(SubscriptionsServiceProvider::shouldRegisterEventBusFallback(StrictLaneRegistration::BUS));
        self::assertFalse(SubscriptionsServiceProvider::shouldRegisterEventBusFallback(StrictLaneRegistration::STRICT));
        self::assertFalse(SubscriptionsServiceProvider::shouldRegisterEventBusFallback(StrictLaneRegistration::NONE));
    }

    /**
     * Task 7 -- the real boot() S7 branch, exercised end-to-end against REAL
     * EventService/EventDispatcher/ListenerProvider instances (no fakes): in
     * this repo's real environment (payvia ^2.4 present => strict mode), boot()
     * must register NO `PaymentProviderEvent` listener on the ordinary bus --
     * the strict lane is wired exclusively through services()/tags(), never
     * through addListener() -- PROVIDED the container actually carries the
     * strict tag (a correctly built/compiled container). The tag is bound
     * here to represent that "healthy" case; the skew case below
     * (tag absent) is the fix-round regression this pairs with.
     * ListenerProvider::getListenersForType() lets us assert this without
     * dispatching anything.
     */
    public function testBootInRealStrictEnvironmentRegistersNoOrdinaryBusListenerWhenTheStrictTagIsPresent(): void
    {
        $listenerProvider = new ListenerProvider();
        // The container arg is required: EventService::addListener('@id') throws
        // LogicException without one -- it's only used lazily at DISPATCH time
        // (never exercised here), so the harness's throw-on-unknown-id container
        // is a fine (if inert) third argument.
        $eventService = new EventService(
            new EventDispatcher($listenerProvider),
            $listenerProvider,
            $this->appContext()->getContainer()
        );
        $this->bind(EventService::class, $eventService);
        // Represents a correctly built container: the strict tag IS bound
        // (its actual contents don't matter for the has() check boot() makes).
        $this->bind(StrictPaymentEventListener::CONTAINER_TAG, []);

        $provider = new SubscriptionsServiceProvider($this->appContext()->getContainer());

        $logFile = tempnam(sys_get_temp_dir(), 'subscriptions-error-log-');
        $previousErrorLog = ini_set('error_log', $logFile);
        try {
            $provider->boot($this->appContext());
        } finally {
            ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
        }
        $logged = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertSame([], $listenerProvider->getListenersForType(PaymentProviderEvent::class));
        self::assertStringNotContainsString('CRITICAL', $logged);
    }

    /**
     * Task 7 fix round (review finding #1 -- compile-time/boot-time mode
     * skew): reproduces upgrading payvia 2.3 -> 2.4 with a STALE compiled
     * container. In this repo's real environment strictLaneMode() always
     * resolves STRICT (payvia ^2.4 is a permanent require-dev fixture), but
     * this harness's container never binds
     * StrictPaymentEventListener::CONTAINER_TAG -- exactly modeling a
     * compiled container built before that tag existed. Unguarded, this
     * combination would register NEITHER lane (silently dead projection);
     * boot() must instead log a loud, named error AND fall back to
     * registering the degraded bus listener so delivery isn't silently zero.
     */
    public function testBootFallsBackToTheBusListenerAndLogsLoudlyWhenTheStrictTagIsMissingFromTheContainer(): void
    {
        $listenerProvider = new ListenerProvider();
        // The container arg is required: EventService::addListener('@id') throws
        // LogicException without one -- it's only used lazily at DISPATCH time
        // (never exercised here), so the harness's throw-on-unknown-id container
        // is a fine (if inert) third argument.
        $eventService = new EventService(
            new EventDispatcher($listenerProvider),
            $listenerProvider,
            $this->appContext()->getContainer()
        );
        $this->bind(EventService::class, $eventService);
        // Deliberately NOT binding StrictPaymentEventListener::CONTAINER_TAG --
        // the harness's has() therefore returns false for it, exactly like a
        // stale compiled container that predates the strict tag.

        $provider = new SubscriptionsServiceProvider($this->appContext()->getContainer());

        $logFile = tempnam(sys_get_temp_dir(), 'subscriptions-error-log-');
        $previousErrorLog = ini_set('error_log', $logFile);
        try {
            $provider->boot($this->appContext());
        } finally {
            ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
        }
        $logged = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertStringContainsString('CRITICAL', $logged);
        self::assertStringContainsString('stale compiled container', $logged);
        self::assertStringContainsString(StrictPaymentEventListener::CONTAINER_TAG, $logged);

        // Fix wave I4: the diagnostic must name the DATA LOSS the fallback
        // causes, not merely the fact that it happened -- fault-isolated bus
        // dispatch swallows the projector's retryable-unmapped signal, so
        // unmapped events are permanently lost rather than retried later.
        self::assertStringContainsString('DATA LOSS', $logged);
        self::assertStringContainsString('PERMANENTLY LOST', $logged);
        self::assertStringContainsString('not retried later', $logged);

        $listeners = $listenerProvider->getListenersForType(PaymentProviderEvent::class);
        self::assertCount(1, $listeners, 'the degraded bus fallback listener must be registered');
    }
}
