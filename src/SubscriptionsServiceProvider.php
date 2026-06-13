<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Subscriptions\Bridge\PayviaProviderStatePuller;
use Glueful\Extensions\Subscriptions\Bridge\PayviaSubscriptionEventBridge;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\ProviderStatePullerInterface;
use Glueful\Extensions\Subscriptions\Contracts\SubscriptionEventProjectorInterface;
use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Extensions\Subscriptions\Http\RequireEntitlement;
use Glueful\Extensions\Subscriptions\Http\RequirePlanManagementPermission;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Projection\SubscriptionEventProjector;
use Glueful\Extensions\Subscriptions\RateLimiting\EntitlementTierResolver;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
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
     * The `require_entitlement` middleware alias is declared here -- the router
     * resolves string middleware names through the container, which compiles
     * before boot(), so boot() would be too late (mirrors tenancy's `tenant`).
     *
     * @return array<string, mixed>
     */
    public static function services(): array
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
            PlanController::class => [
                'class' => PlanController::class,
                'shared' => true,
                'autowire' => true,
            ],
            SubscriptionEventProjectorInterface::class => [
                'class' => SubscriptionEventProjector::class,
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
        if (class_exists(\Glueful\Extensions\Payvia\Services\GatewaySubscriptionService::class)) {
            $defs[ProviderStatePullerInterface::class] = [
                'class' => PayviaProviderStatePuller::class,
                'shared' => true,
                'autowire' => true,
            ];
        }

        return $defs;
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
            $puller,
        );
    }

    /** @return array<string, class-string> */
    public static function middlewareAliases(): array
    {
        return [
            'require_entitlement' => RequireEntitlement::class,
            'subscriptions_plans_manage' => RequirePlanManagementPermission::class,
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
    }

    public function boot(ApplicationContext $context): void
    {
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

        // S7: project payvia provider events onto subscription state -- but ONLY
        // when payvia is installed (soft dep). Lazy '@serviceId' listener so the
        // projection pipeline is constructed on first dispatch, not at boot.
        try {
            if (class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class)) {
                app($context, \Glueful\Events\EventService::class)->addListener(
                    \Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class,
                    '@' . PayviaSubscriptionEventBridge::class
                );
            }
        } catch (\Throwable $e) {
            error_log('[Subscriptions] Failed to register payvia event listener: ' . $e->getMessage());
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
