<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Subscriptions\Bridge\PayviaProviderStatePuller;
use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Bridge\StrictLaneRegistration;
use Glueful\Extensions\Subscriptions\Bridge\StrictPayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Extensions\Subscriptions\Http\RequireEntitlement;
use Glueful\Extensions\Subscriptions\Http\RequireMemberEntitlement;
use Glueful\Extensions\Subscriptions\Http\RequirePlanManagementPermission;
use Glueful\Extensions\Subscriptions\Lifecycle\SubscriptionSubjectDataPurger;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\RateLimiting\EntitlementTierResolver;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\ProviderEventReceiptRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\Resolution\MemberEntitlementResolverFactory;
use Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness;
use Psr\Container\ContainerInterface;

final class SubscriptionsServiceProvider extends ServiceProvider
{
    private static ?string $cachedVersion = null;

    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
            self::$cachedVersion = (string) ($composer['extra']['glueful']['version'] ?? '0.0.0');
        }

        return self::$cachedVersion;
    }

    /**
     * Service definitions (array DSL + factories).
     *
     * The two interface bindings OVERRIDE framework-core defaults (last-wins,
     * enabled by the container-precedence fix): EntitlementCheckerInterface over
     * core's allow-all NullEntitlementChecker, and TierResolverInterface over the
     * default TierResolver (which EntitlementTierResolver wraps and delegates to).
     *
     * The `require_entitlement` and `require_member_entitlement` middleware
     * aliases are declared here -- the router resolves string middleware names
     * through the container, which compiles before boot(), so boot() would be
     * too late (mirrors tenancy's `tenant`).
     *
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        return self::serviceDefinitionsForMode(
            self::strictLaneMode(),
            class_exists(\Glueful\Extensions\Payvia\Services\GatewaySubscriptionService::class)
        );
    }

    /**
     * Task 7 -- spec §4: the single-lane payvia payment-event registration mode
     * for THIS install. `StrictLaneRegistration::decide()` is a pure function
     * of two booleans; this method is the ONLY place that supplies them from
     * the real runtime (`interface_exists`/`class_exists`). Since payvia ^2.4
     * has been a permanent require-dev fixture since Task 5, this always
     * resolves to STRICT in-process -- the BUS/NONE branches are exercised in
     * tests via {@see serviceDefinitionsForMode()}, {@see tagsForMode()}, and
     * {@see shouldRegisterEventBusFallback()}, called directly with an
     * explicit mode rather than through this method.
     */
    private static function strictLaneMode(): string
    {
        $strictContractPresent = interface_exists(
            \Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener::class
        );

        // Short-circuit: decide() ignores $payviaEventPresent whenever the strict
        // contract is present, so skip the PaymentProviderEvent probe entirely in
        // that (here, the ONLY reachable in-process) case -- it would otherwise
        // autoload payvia's ordinary event class as a pure side effect of every
        // services()/tags()/boot() call, even for hosts/tests that never touch the
        // bus lane at all (see CustomProviderExampleTest, which proves the BYOP
        // path never needs payvia loaded).
        return StrictLaneRegistration::decide(
            $strictContractPresent,
            $strictContractPresent || class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class)
        );
    }

    /**
     * PURE service definition builder (Task 7, fix round): takes both capability
     * inputs as explicit parameters -- `$mode` and `$payviaRuntimePresent` -- and
     * performs NO `interface_exists`/`class_exists` probing of its own. Every
     * definition below is unconditional except: the `ProviderStatePullerInterface`
     * binding, present only when `$payviaRuntimePresent`; and the strict-adapter
     * entry at the bottom, present only in {@see StrictLaneRegistration::STRICT}
     * mode. {@see services()} is the ONLY caller that supplies the live probes;
     * every other caller (tests, the compiled-container gate) passes both
     * explicitly, so all combinations -- including `bus` with payvia present and
     * `none` with payvia genuinely absent -- are reachable without runtime class
     * fakery.
     *
     * @return array<string, mixed>
     */
    public static function serviceDefinitionsForMode(string $mode, bool $payviaRuntimePresent): array
    {
        $defs = [
            \Glueful\Entitlements\Contracts\EntitlementCheckerInterface::class => [
                'class' => DefaultEntitlementChecker::class,
                'shared' => true,
                'autowire' => true,
            ],
            \Glueful\Api\RateLimiting\Contracts\TierResolverInterface::class => [
                'class' => EntitlementTierResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Config-driven -- built from the resolved context, hence a factory.
            PlanCatalog::class => [
                'factory' => [self::class, 'makePlanCatalog'],
                'shared' => true,
            ],
            // The ONLY seam through which host identity knowledge enters the engine
            // (spec §4). The shipped default rejects every user subject, so
            // memberships stay inert until a host REBINDS this to a resolver that
            // can vouch for users -- binding the resolver is enabling memberships.
            SubjectResolverInterface::class => [
                'class' => DefaultSubjectResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            SubscriptionRepository::class => [
                'class' => SubscriptionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            OverrideRepository::class => [
                'class' => OverrideRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            SubscriptionEventRepository::class => [
                'class' => SubscriptionEventRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            SubscriptionPlanRepository::class => [
                'class' => SubscriptionPlanRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            PlanPayloadValidator::class => [
                'class' => PlanPayloadValidator::class,
                'shared' => true,
                'autowire' => true,
            ],
            PlanManagementService::class => [
                'class' => PlanManagementService::class,
                'shared' => true,
                'autowire' => true,
            ],
            EffectivePlanResolver::class => [
                'class' => EffectivePlanResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Reads subscriptions.cache config; CacheStore is OPTIONAL (B3) --
            // a zero-infra install resolves uncached.
            EntitlementResolver::class => [
                'factory' => [self::class, 'makeEntitlementResolver'],
                'shared' => true,
            ],
            // Explicit factory: resolves the optional ProviderStatePuller seam
            // (bound only when a provider is installed; null otherwise).
            SubscriptionService::class => [
                'factory' => [self::class, 'makeSubscriptionService'],
                'shared' => true,
            ],
            RequireEntitlement::class => [
                'class' => RequireEntitlement::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['require_entitlement'],
            ],
            RequirePlanManagementPermission::class => [
                'class' => RequirePlanManagementPermission::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['subscriptions_plans_manage'],
            ],
            // Task 14 -- spec §5/§11: the workspace-MEMBER middleware. Holds no
            // tenant-scoped state itself -- it injects MemberEntitlementResolverFactory
            // (below) and only asks it to build a workspace-scoped resolver INSIDE
            // handle(), after its own currentTenant() read -- so, exactly like
            // RequireEntitlement, an ordinary SHARED singleton is safe here.
            RequireMemberEntitlement::class => [
                'class' => RequireMemberEntitlement::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['require_member_entitlement'],
            ],
            PlanController::class => [
                'class' => PlanController::class,
                'shared' => true,
                'autowire' => true,
            ],
            // ProviderEventReceiptRepository has no constructor dependencies and
            // carries no per-request state, so it is an ordinary shared, autowired
            // service (Task 14) -- makeSubscriptionEventProjector() below now
            // resolves it from the container instead of constructing it directly.
            ProviderEventReceiptRepository::class => [
                'class' => ProviderEventReceiptRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            SubscriptionEventProjectorInterface::class => [
                'factory' => [self::class, 'makeSubscriptionEventProjector'],
                'shared' => true,
            ],
            // Task 14 (fixed post-review) -- spec §5/§11.6: a STATELESS factory
            // (see its own docblock) that builds a workspace-scoped
            // MemberEntitlementResolver ON DEMAND, from a caller-supplied
            // tenantUuid, rather than baking any tenant into itself at DI time.
            // Router::executeWithMiddleware() resolves every middleware from the
            // container in one pre-pass BEFORE any handle() runs, so reading
            // SubjectResolverInterface::currentTenant() at container-build time
            // (the original design) can bake a stale/empty scope when a
            // route-level tenancy middleware ordered earlier in the same stack
            // has not yet run its own handle(). This factory itself has no
            // per-tenant state, so it IS safe to share.
            MemberEntitlementResolverFactory::class => [
                'factory' => [self::class, 'makeMemberEntitlementResolverFactory'],
                'shared' => true,
            ],
            // Task 14 -- spec §9: host-neutral subject/tenant data purge. No
            // per-request state (ApplicationContext is autowired per resolution
            // like every other consumer here); hosts decide when to invoke it.
            SubscriptionSubjectDataPurger::class => [
                'class' => SubscriptionSubjectDataPurger::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Task 3 (2.1.0 seams, Phase A) -- the extension-owned schema
            // readiness authority Thallo's EngineGateway calls to distinguish
            // schema_not_ready from ready. No per-request state (ApplicationContext
            // is autowired per resolution, same as every other consumer here).
            SubscriptionSchemaReadiness::class => [
                'class' => SubscriptionSchemaReadiness::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Registered as a service so the '@serviceId' lazy listener resolves.
            PayviaSubscriptionEventBridge::class => [
                'class' => PayviaSubscriptionEventBridge::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Safe to register unconditionally: the puller depends only on
            // ApplicationContext and names payvia solely via a runtime string
            // FQCN inside pull(), so it autoloads/constructs even with payvia absent.
            PayviaProviderStatePuller::class => [
                'class' => PayviaProviderStatePuller::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];

        // Bind the reconcile puller to the payvia implementation ONLY when payvia
        // is installed. Absent payvia, ProviderStatePullerInterface stays unbound
        // and SubscriptionService resolves a null puller (reconcile no-ops). A
        // third-party provider binds this interface to its own puller instead.
        // (Fix round: this used to probe class_exists() live, right here, which
        // made this method impure. services() now supplies the live probe.)
        if ($payviaRuntimePresent) {
            $defs[ProviderStatePullerInterface::class] = [
                'class' => PayviaProviderStatePuller::class,
                'shared' => true,
                'autowire' => true,
            ];
        }

        // Task 7 -- spec §4: the strict payment-event lane adapter is registered
        // ONLY in strict mode. It is deliberately ABSENT (not merely unused) from
        // the bus/none definition maps -- see the compiled-container gate
        // (tests/Unit/Container/StrictLaneCompiledContainerGateTest.php), which
        // proves neither this class nor payvia's StrictPaymentEventListener is
        // ever reflected/autoloaded while building or resolving those maps.
        //
        // The 'tags' key here (NOT tags()/tagsForMode() below) is what actually
        // wires the tag for THIS provider: ContainerFactory::loadExtensionDefinitions()
        // only ever consults a provider's static tags() for typed defs()-based
        // providers (applyProviderTags(), gated on method_exists($class, 'defs'));
        // a services()-based (DSL) provider -- what this class is -- is tagged
        // exclusively via applyDslTags() reading each definition's own 'tags' key.
        // Verified empirically against the real framework: without this key the
        // adapter is registered as a plain, untagged service and payvia's
        // composeStrictLane() never sees it. tags()/tagsForMode() are kept per
        // the accepted design, but they do NOT make this provider migration-safe
        // by themselves: loadExtensionDefinitions() picks defs() over services()
        // whenever a provider exposes BOTH ("defs() wins; skip DSL" -- the DSL
        // branch, including this whole definition and its 'tags' key, would
        // never run at all). A real migration to defs() must re-port this
        // definition (and its tag) into typed Definition objects there too; see
        // tags()'s docblock.
        if ($mode === StrictLaneRegistration::STRICT) {
            $defs[StrictPayviaSubscriptionEventBridge::class] = [
                'class' => StrictPayviaSubscriptionEventBridge::class,
                'shared' => true,
                'autowire' => true,
                'tags' => [\Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener::CONTAINER_TAG],
            ];
        }

        return $defs;
    }

    /**
     * Task 7 -- spec §4: the container tags this provider publishes via the
     * typed-provider convention (`ContainerFactory::applyProviderTags()`,
     * called as `$providerClass::tags()` with NO arguments -- so this method
     * itself cannot take a mode parameter; {@see tagsForMode()} is the pure,
     * testable helper it delegates to).
     *
     * NOTE -- verified against the real framework: `ContainerFactory` only
     * ever calls a provider's `tags()` when that provider exposes a typed
     * `defs()` (`applyProviderTags()` is invoked exclusively from the
     * `defs()` branch of `loadExtensionDefinitions()`). This provider is
     * `services()`-based (DSL), so this method is NOT presently reachable
     * from real container construction -- the strict adapter's `'tags'` key
     * on its own definition (see {@see serviceDefinitionsForMode()}), read by
     * `applyDslTags()`, is what actually wires the tag today. `tags()` is kept
     * per the accepted design doc, but keeping it does NOT by itself make a
     * future migration to `defs()` safe: `loadExtensionDefinitions()` prefers
     * `defs()` over `services()` whenever a provider exposes both ("defs() wins;
     * skip DSL"), so the DSL branch -- the strict adapter's definition AND its
     * `'tags'` key together -- would simply stop running, not just its tag. A
     * real migration must re-port the definition itself into typed Definition
     * objects in `defs()` too; `tags()` only tags an id that `defs()` would then
     * need to already define.
     *
     * @return array<string, array<int, string>>
     */
    public static function tags(): array
    {
        return self::tagsForMode(self::strictLaneMode());
    }

    /**
     * Pure, mode-parameterized tag builder (Task 7), mirroring
     * {@see serviceDefinitionsForMode()}: publishes the strict adapter under
     * payvia's `StrictPaymentEventListener::CONTAINER_TAG` in strict mode ONLY.
     * The payvia constant is referenced only after `$mode` is already confirmed
     * strict (i.e. only when `interface_exists()` already found it), so this
     * never touches payvia at all for bus/none.
     *
     * @return array<string, array<int, string>>
     */
    public static function tagsForMode(string $mode): array
    {
        if ($mode !== StrictLaneRegistration::STRICT) {
            return [];
        }

        return [
            \Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener::CONTAINER_TAG => [
                StrictPayviaSubscriptionEventBridge::class,
            ],
        ];
    }

    /**
     * Pure, testable companion to the S7 boot() branch (Task 7): whether the
     * degraded fault-isolated bus fallback listener should be registered for
     * the given mode. Extracted so the bus-only wiring rule is assertable
     * without needing `interface_exists`/`class_exists` to lie at runtime.
     */
    public static function shouldRegisterEventBusFallback(string $mode): bool
    {
        return $mode === StrictLaneRegistration::BUS;
    }

    public static function makePlanCatalog(ContainerInterface $c): PlanCatalog
    {
        return PlanCatalog::fromContext($c->get(ApplicationContext::class));
    }

    public static function makeEntitlementResolver(ContainerInterface $c): EntitlementResolver
    {
        $context = $c->get(ApplicationContext::class);
        $cacheConfig = (array) config($context, 'subscriptions.cache', []);

        return new EntitlementResolver(
            $c->get(PlanCatalog::class),
            $c->get(SubscriptionRepository::class),
            $c->get(OverrideRepository::class),
            $c->get(EffectivePlanResolver::class),
            $c->has(CacheStore::class) ? $c->get(CacheStore::class) : null,
            (bool) ($cacheConfig['enabled'] ?? true),
            (int) ($cacheConfig['ttl'] ?? 300),
        );
    }

    public static function makeSubscriptionEventProjector(ContainerInterface $c): SubscriptionEventProjector
    {
        return new SubscriptionEventProjector(
            $c->get(SubscriptionRepository::class),
            $c->get(SubscriptionEventRepository::class),
            $c->get(ProviderEventReceiptRepository::class),
            $c->get(PlanCatalog::class),
            $c->get(ApplicationContext::class),
            $c->get(SubjectResolverInterface::class),
        );
    }

    /**
     * Task 14 (fixed post-review) -- spec §5/§11.6: builds the STATELESS
     * {@see MemberEntitlementResolverFactory}. Deliberately does NOT read
     * `SubjectResolverInterface::currentTenant()` here -- that read must
     * happen inside `RequireMemberEntitlement::handle()`, not at DI
     * resolution time (see the factory class's own docblock for why).
     */
    public static function makeMemberEntitlementResolverFactory(ContainerInterface $c): MemberEntitlementResolverFactory
    {
        $context = $c->get(ApplicationContext::class);
        $cacheConfig = (array) config($context, 'subscriptions.cache', []);

        return new MemberEntitlementResolverFactory(
            $c->get(SubscriptionRepository::class),
            $c->get(OverrideRepository::class),
            $c->get(EffectivePlanResolver::class),
            $c->has(CacheStore::class) ? $c->get(CacheStore::class) : null,
            (bool) ($cacheConfig['enabled'] ?? true),
            (int) ($cacheConfig['ttl'] ?? 300),
        );
    }

    public static function makeSubscriptionService(ContainerInterface $c): SubscriptionService
    {
        $puller = $c->has(ProviderStatePullerInterface::class)
            ? $c->get(ProviderStatePullerInterface::class)
            : null;

        return new SubscriptionService(
            $c->get(SubscriptionRepository::class),
            $c->get(SubscriptionEventRepository::class),
            $c->get(PlanCatalog::class),
            $c->get(ApplicationContext::class),
            $c->get(SubjectResolverInterface::class),
            $puller,
        );
    }

    /** @return array<string, class-string> */
    public static function middlewareAliases(): array
    {
        return [
            'require_entitlement' => RequireEntitlement::class,
            'subscriptions_plans_manage' => RequirePlanManagementPermission::class,
            'require_member_entitlement' => RequireMemberEntitlement::class,
        ];
    }

    public function getName(): string
    {
        return 'Subscriptions';
    }

    public function getVersion(): string
    {
        return self::composerVersion();
    }

    public function getDescription(): string
    {
        return 'Tenant subscriptions and entitlement resolution for Glueful SaaS apps.';
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('subscriptions', require __DIR__ . '/../config/subscriptions.php');
    }

    public function boot(ApplicationContext $context): void
    {
        try {
            $this->loadMigrationsFrom(
                __DIR__ . '/../migrations',
                MigrationPriority::DEPENDENT,
                'glueful/subscriptions'
            );
        } catch (\Throwable $e) {
            error_log('[Subscriptions] Failed to register migrations: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e; // fail fast in non-production
            }
        }

        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'subscriptions',
                'name' => $this->getName(),
                'version' => $this->getVersion(),
                'description' => $this->getDescription(),
            ]);
        } catch (\Throwable $e) {
            error_log('[Subscriptions] Failed to register extension metadata: ' . $e->getMessage());
        }

        try {
            $this->discoverCommands('Glueful\\Extensions\\Subscriptions\\Console', __DIR__ . '/Console');
        } catch (\Throwable $e) {
            error_log('[Subscriptions] Failed to discover commands: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e; // fail fast in non-production
            }
        }

        try {
            $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        } catch (\Throwable $e) {
            error_log('[Subscriptions] Failed to load routes: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e; // fail fast in non-production
            }
        }

        // S7 (Task 7 -- spec §4): exactly ONE payvia payment-event lane is ever
        // wired. Strict mode tags StrictPayviaSubscriptionEventBridge onto
        // payvia's composed dispatcher via services()/tags() instead (no
        // addListener() call here at all). This block registers ONLY the
        // degraded fault-isolated fallback for payvia <=2.3 -- the
        // retryable-unmapped guarantee requires >=2.4 -- via the existing lazy
        // '@serviceId' listener so the projection pipeline is constructed on
        // first dispatch, not at boot.
        //
        // Compile-time/boot-time mode SKEW guard (fix round): the mode is
        // computed from live probes in TWO places -- services() (frozen into a
        // compiled container at build time) and here in boot() (re-probed on
        // every request). Upgrading payvia 2.3 -> 2.4 without recompiling the
        // container reproduces this exactly: the compiled container was built
        // while payvia was still <=2.3 (or absent), so it carries no
        // StrictPaymentEventListener::CONTAINER_TAG entry, but boot() now sees
        // payvia >=2.4 live and computes strict -- which, left unguarded,
        // registers NEITHER lane (strict adds nothing new at boot; the bus
        // branch below is skipped because mode isn't 'bus') and subscription
        // projection goes silently dead. Degraded bus delivery beats zero
        // delivery, so strict mode falls back to the bus listener whenever the
        // tag isn't actually bound in the live container, and logs loudly --
        // the real fix is recompiling/invalidating the stale container, not
        // silently tolerating the fallback forever. The fallback is LOSSY, and
        // the log says so plainly: fault-isolated bus dispatch catches and logs
        // listener exceptions, which swallows the projector's retryable-unmapped
        // signal -- unmapped events are PERMANENTLY LOST, not retried later.
        try {
            $mode = self::strictLaneMode();
            if ($mode === StrictLaneRegistration::STRICT) {
                $strictTagBound = $context->hasContainer()
                    && container($context)->has(
                        \Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener::CONTAINER_TAG
                    );

                if (!$strictTagBound) {
                    error_log(
                        '[Subscriptions] CRITICAL: strict payment-event lane mode but the container has '
                        . 'no ' . \Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener::CONTAINER_TAG
                        . ' tag bound -- this looks like a stale compiled container built before payvia '
                        . 'was upgraded to >=2.4 (or before payvia was installed at all). Falling back to '
                        . 'the degraded bus listener so subscription projection is not silently dead. '
                        . 'DATA LOSS: under bus/fallback delivery the retryable-unmapped signal is '
                        . 'swallowed by fault-isolated dispatch, so unmapped events are PERMANENTLY LOST, '
                        . 'not retried later. Recompile/invalidate the compiled container '
                        . '(e.g. di:container:compile --force) to restore the strict lane.'
                    );
                    app($context, \Glueful\Events\EventService::class)->addListener(
                        \Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class,
                        '@' . PayviaSubscriptionEventBridge::class
                    );
                }
            } elseif (self::shouldRegisterEventBusFallback($mode)) {
                app($context, \Glueful\Events\EventService::class)->addListener(
                    \Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class,
                    '@' . PayviaSubscriptionEventBridge::class
                );
            }
        } catch (\Throwable $e) {
            error_log('[Subscriptions] Failed to register payvia event bridge: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e; // fail fast in non-production
            }
        }

        // Tenant table registration (spec §9): `glueful/extension-contracts` is a
        // require-dev-only dependency (never a hard one), so this is a SOFT probe --
        // interface_exists() first (the contracts package may not even be
        // autoloadable), then the container's own has() before ever calling get().
        // Registered OUTSIDE any feature gate: exactly the three conventional tenant
        // tables (subscriptions, subscription_overrides, subscription_events).
        // subscription_plans (mixed platform/workspace ownership under a
        // differently-named owner column) and subscription_provider_event_receipts
        // (rejected candidates may carry no valid tenant) are deliberately NEVER
        // registered.
        try {
            if (interface_exists(TenantTableRegistry::class) && $context->hasContainer()) {
                $container = container($context);
                if ($container->has(TenantTableRegistry::class)) {
                    $registry = $container->get(TenantTableRegistry::class);
                    if ($registry instanceof TenantTableRegistry) {
                        $registry->register(['subscriptions', 'subscription_overrides', 'subscription_events']);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[Subscriptions] Failed to register tenant tables: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e; // fail fast in non-production
            }
        }
    }

    private function bootEnv(): string
    {
        return (string) ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production'));
    }
}
