<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Http\PlanController;
use Glueful\Extensions\Subscriptions\Http\RequireEntitlement;
use Glueful\Extensions\Subscriptions\Http\RequirePlanManagementPermission;
use Glueful\Extensions\Subscriptions\Listeners\PaymentProviderEventListener;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
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
        return [
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
            PlanCatalog::class => new FactoryDefinition(
                PlanCatalog::class,
                static fn(ContainerInterface $c): PlanCatalog =>
                    PlanCatalog::fromContext($c->get(ApplicationContext::class))
            ),
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
            EntitlementResolver::class => new FactoryDefinition(
                EntitlementResolver::class,
                static function (ContainerInterface $c): EntitlementResolver {
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
            ),
            // Explicit factory: the optional payvia puller seam stays at its
            // default (the service itself resolves payvia via class_exists).
            SubscriptionService::class => new FactoryDefinition(
                SubscriptionService::class,
                static fn(ContainerInterface $c): SubscriptionService => new SubscriptionService(
                    $c->get(SubscriptionRepository::class),
                    $c->get(SubscriptionEventRepository::class),
                    $c->get(PlanCatalog::class),
                    $c->get(ApplicationContext::class),
                )
            ),
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
            // Registered as a service so the '@serviceId' lazy listener resolves.
            PaymentProviderEventListener::class => [
                'class' => PaymentProviderEventListener::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
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
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'glueful/subscriptions'
        );
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

        $this->discoverCommands('Glueful\\Extensions\\Subscriptions\\Console', __DIR__ . '/Console');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

        // S7: project payvia provider events onto subscription state -- but ONLY
        // when payvia is installed (soft dep). Lazy '@serviceId' listener so the
        // projection pipeline is constructed on first dispatch, not at boot.
        if (class_exists(\Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class)) {
            app($context, \Glueful\Events\EventService::class)->addListener(
                \Glueful\Extensions\Payvia\Events\PaymentProviderEvent::class,
                '@' . PaymentProviderEventListener::class
            );
        }
    }
}
