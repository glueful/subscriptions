<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;

final class EntitlementResolver
{
    /** @param CacheStore<mixed>|null $cache */
    public function __construct(
        private readonly PlanCatalog $catalog,
        private readonly SubscriptionRepository $subscriptions,
        private readonly OverrideRepository $overrides,
        private readonly EffectivePlanResolver $planResolver,
        private readonly ?CacheStore $cache = null,
        private readonly bool $cacheEnabled = true,
        private readonly int $cacheTtl = 300,
    ) {
    }

    /** @return array<string,mixed> */
    public function resolveMap(ApplicationContext $context, string $tenantUuid): array
    {
        // The subscription row is needed for the cache key anyway (cheap read);
        // remember() then guards the heavier override merge (S12 -- key-derived
        // invalidation: any subscription/override/catalog change changes the key).
        $subscription = $this->subscriptions->findByTenant($context, $tenantUuid);

        if (!$this->cacheEnabled || $this->cache === null) {
            return $this->resolveFresh($context, $tenantUuid, $subscription);
        }

        $cacheKey = $this->cacheKey($context, $tenantUuid, $subscription);
        $resolved = $this->cache->remember(
            $cacheKey,
            fn (): array => $this->resolveFresh($context, $tenantUuid, $subscription),
            $this->cacheTtl
        );

        return is_array($resolved) ? $resolved : $this->resolveFresh($context, $tenantUuid, $subscription);
    }

    /**
     * @param array<string,mixed>|null $subscription
     * @return array<string,mixed>
     */
    private function resolveFresh(ApplicationContext $context, string $tenantUuid, ?array $subscription): array
    {
        $planKey = $this->planResolver->resolve(
            $subscription,
            $this->catalog->defaultPlan(),
            new \DateTimeImmutable('now')
        );
        $map = $this->catalog->entitlementsFor($planKey);

        foreach ($this->overrides->activeForTenant($context, $tenantUuid) as $key => $value) {
            $map[$key] = $value;
        }

        return $map;
    }

    /** @param array<string,mixed>|null $subscription */
    private function cacheKey(ApplicationContext $context, string $tenantUuid, ?array $subscription): string
    {
        return implode(':', [
            'subscriptions.ent',
            $tenantUuid,
            $this->catalog->version(),
            $subscription === null ? '0' : ($this->subscriptions->latestUpdatedAt($subscription) ?? '0'),
            $this->overrides->maxUpdatedAt($context, $tenantUuid) ?? '0',
        ]);
    }
}
