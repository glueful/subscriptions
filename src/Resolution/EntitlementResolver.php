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
        // The subscription row and the active override map are both needed for the
        // cache key, and the override map is reused by resolveFresh so it is read
        // exactly once here (no second query). The key folds in the resolved-plan
        // inputs and a stable hash of the override map, so it is intrinsically
        // sensitive to security-relevant changes -- a status/plan downgrade or an
        // override edit invalidates the cache even when updated_at was not bumped
        // (S12 -- content-derived invalidation).
        $subscription = $this->subscriptions->findByTenant($context, $tenantUuid);
        $overrides = $this->overrides->activeForTenant($context, $tenantUuid);

        if (!$this->cacheEnabled || $this->cache === null) {
            return $this->resolveFresh($subscription, $overrides);
        }

        $cacheKey = $this->cacheKey($tenantUuid, $subscription, $overrides);
        $resolved = $this->cache->remember(
            $cacheKey,
            fn (): array => $this->resolveFresh($subscription, $overrides),
            $this->cacheTtl
        );

        return is_array($resolved) ? $resolved : $this->resolveFresh($subscription, $overrides);
    }

    /**
     * @param array<string,mixed>|null $subscription
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function resolveFresh(?array $subscription, array $overrides): array
    {
        $planKey = $this->planResolver->resolve(
            $subscription,
            $this->catalog->defaultPlan(),
            new \DateTimeImmutable('now')
        );
        $map = $this->catalog->entitlementsFor($planKey);

        foreach ($overrides as $key => $value) {
            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * @param array<string,mixed>|null $subscription
     * @param array<string,mixed> $overrides
     */
    private function cacheKey(string $tenantUuid, ?array $subscription, array $overrides): string
    {
        return implode(':', [
            'subscriptions.ent',
            $tenantUuid,
            $this->catalog->version(),
            $this->subscriptionSignature($subscription),
            $this->overridesSignature($overrides),
        ]);
    }

    /**
     * Stable signature of the subscription fields EffectivePlanResolver reads, so
     * the key differs the moment a downgrade/cancel changes the resolved plan --
     * independent of updated_at. Mirrors the resolver's inputs exactly: status,
     * plan_key, and grace_ends_at (consulted only for past_due grace).
     *
     * @param array<string,mixed>|null $subscription
     */
    private function subscriptionSignature(?array $subscription): string
    {
        if ($subscription === null) {
            return 'none';
        }

        return $this->hash([
            'status' => $subscription['status'] ?? null,
            'plan_key' => $subscription['plan_key'] ?? null,
            'grace_ends_at' => $subscription['grace_ends_at'] ?? null,
        ]);
    }

    /**
     * Stable hash of the active override map (added/changed/removed/expired
     * overrides all change it), strictly stronger than a maxUpdatedAt timestamp.
     *
     * @param array<string,mixed> $overrides
     */
    private function overridesSignature(array $overrides): string
    {
        if ($overrides === []) {
            return '0';
        }

        ksort($overrides);

        return $this->hash($overrides);
    }

    /** @param array<string,mixed> $data */
    private function hash(array $data): string
    {
        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        return substr(hash($algo, $encoded), 0, 16);
    }
}
