<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Psr\Log\LoggerInterface;

/**
 * The workspace-member entitlement path (spec §11): Subject::user($tenantUuid,
 * $userUuid) against a WORKSPACE-scoped PlanCatalog (audience='user',
 * owner_tenant_uuid=$tenantUuid), mirroring EntitlementResolver's shape but never
 * calling PlanCatalog::defaultPlan() -- that method throws outside the platform
 * scope. A member's "no plan" case passes an explicit '' default into
 * EffectivePlanResolver instead: absent membership resolves to an empty BASE map,
 * never an implicit free/default plan (unlike the tenant path).
 *
 * `rate.tier.*` keys are a tenant-billing-only rate-limit concept (see
 * RateLimiting\EntitlementTierResolver); a member map must never leak one even if
 * a workspace plan's entitlements JSON happens to carry it (e.g. copy/pasted from
 * a platform plan), so the resolved map is filtered before it is returned.
 *
 * The $catalog dependency must already be scoped to this exact ($tenantUuid)
 * workspace by the caller (PlanCatalog::forScope($context, 'user', $tenantUuid))
 * -- unlike the tenant path's single global platform catalog, a workspace catalog
 * is inherently tenant-specific, so resolveMap()'s $tenantUuid parameter is used
 * for the subject lookup/cache key, not for re-deriving the catalog's scope.
 */
final class MemberEntitlementResolver
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
    public function resolveMap(ApplicationContext $context, string $tenantUuid, string $userUuid): array
    {
        $subject = Subject::user($tenantUuid, $userUuid);
        $subscription = $this->subscriptions->findBySubject($context, $subject);
        $overrides = $this->overrides->activeForSubject($context, $subject);

        if (!$this->cacheEnabled || $this->cache === null) {
            return $this->resolveFresh($context, $subscription, $overrides);
        }

        $cacheKey = $this->cacheKey($subject, $subscription, $overrides);
        $resolved = $this->cache->remember(
            $cacheKey,
            fn (): array => $this->resolveFresh($context, $subscription, $overrides),
            $this->cacheTtl
        );

        return is_array($resolved) ? $resolved : $this->resolveFresh($context, $subscription, $overrides);
    }

    /**
     * @param array<string,mixed>|null $subscription
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function resolveFresh(ApplicationContext $context, ?array $subscription, array $overrides): array
    {
        // Empty-string default: a lapsed/absent membership has NO implicit plan
        // (never PlanCatalog::defaultPlan() -- that throws outside platform scope).
        $planKey = $this->planResolver->resolve($subscription, '', new \DateTimeImmutable('now'));
        $map = $planKey === '' ? [] : $this->catalog->entitlementsFor($planKey);

        foreach ($overrides as $key => $value) {
            $map[$key] = $value;
        }

        return $this->stripRateTier($context, $map);
    }

    /**
     * @param array<string,mixed> $map
     * @return array<string,mixed>
     */
    private function stripRateTier(ApplicationContext $context, array $map): array
    {
        $stripped = [];
        $anyStripped = false;

        foreach ($map as $key => $value) {
            if (str_starts_with($key, 'rate.tier.')) {
                $anyStripped = true;
                continue;
            }

            $stripped[$key] = $value;
        }

        if ($anyStripped) {
            // One log line per resolve, not per stripped key -- this is a
            // structural leak (tenant-only concept reaching a member map), not a
            // per-key event.
            $this->resolveLogger($context)?->warning(
                'Stripped rate.tier.* entitlement(s) from a member entitlement map',
                ['event' => 'subscriptions.member_rate_tier_stripped']
            );
        }

        return $stripped;
    }

    /**
     * @param array<string,mixed>|null $subscription
     * @param array<string,mixed> $overrides
     */
    private function cacheKey(Subject $subject, ?array $subscription, array $overrides): string
    {
        return implode(':', [
            'subscriptions.ent',
            $subject->tenantUuid,
            $subject->type,
            $subject->uuid,
            $this->catalog->version(),
            $this->subscriptionSignature($subscription),
            $this->overridesSignature($overrides),
        ]);
    }

    /** @param array<string,mixed>|null $subscription */
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

    /** @param array<string,mixed> $overrides */
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

    /**
     * Resolved DEFENSIVELY (mirrors SubscriptionEventProjector::resolveLogger()):
     * this resolver must never hard-depend on a logging service -- a missing
     * binding turns the stripped-key observability line into a silent no-op, not
     * a fatal.
     */
    private function resolveLogger(ApplicationContext $context): ?LoggerInterface
    {
        if (!$context->hasContainer()) {
            return null;
        }

        $container = $context->getContainer();
        foreach (['logger', LoggerInterface::class] as $id) {
            if ($container->has($id)) {
                $logger = $container->get($id);
                if ($logger instanceof LoggerInterface) {
                    return $logger;
                }
            }
        }

        return null;
    }
}
