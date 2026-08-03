<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Lifecycle\TenantIntegration;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Psr\Log\LoggerInterface;

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
        //
        // Both reads run in SYSTEM mode (spec §9). Task 12 registered
        // `subscriptions` and `subscription_overrides` as tenant-owned tables, so a
        // tenancy host injects the AMBIENT tenant_uuid into every query against
        // them. This API is explicitly PARAMETERIZED by tenant instead
        // (`DefaultEntitlementChecker::allows($someOtherTenant, ...)` is a legitimate,
        // documented call from inside another tenant's request, from a job, or from a
        // CLI with no ambient tenant at all), and the repository predicates already
        // pin the exact subject triple, so ambient injection can only ever narrow a
        // correctly-scoped query to nothing -- turning a cross-tenant entitlement read
        // into a silent, fail-open-looking empty map. Mirrors
        // SubscriptionEventProjector::project()'s own system-mode wrap.
        /** @var array{0:array<string,mixed>|null,1:array<string,mixed>} $reads */
        $reads = TenantIntegration::runAsSystemOr($context, fn (): array => [
            $this->subscriptions->findByTenant($context, $tenantUuid),
            $this->overrides->activeForTenant($context, $tenantUuid),
        ]);
        [$subscription, $overrides] = $reads;

        if (!$this->cacheEnabled || $this->cache === null) {
            return $this->resolveFresh($context, $subscription, $overrides);
        }

        $cacheKey = $this->cacheKey($tenantUuid, $subscription, $overrides);
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
        $defaultPlan = $this->catalog->defaultPlan();
        $this->warnIfDefaultPlanUnresolvable($context, $defaultPlan);

        $planKey = $this->planResolver->resolve(
            $subscription,
            $defaultPlan,
            new \DateTimeImmutable('now')
        );
        $map = $this->catalog->entitlementsFor($planKey);

        foreach ($overrides as $key => $value) {
            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * The spec §3.3 loud diagnostic. Since 2.0 the plan catalog is
     * DB-AUTHORITATIVE: `config('subscriptions.plans')` is a seed, not a runtime
     * overlay. A fresh install that runs `migrate:run` but never
     * `subscriptions:plans:import-config` therefore has an EMPTY
     * `subscription_plans` table, and every entitlement resolves to `[]` -- a
     * silently non-functional install that looks exactly like "this tenant has no
     * entitlements".
     *
     * This logs, it does NOT throw: entitlement checks must stay
     * fail-closed-not-fatal. An install with no catalog denies everything, which is
     * safe; making it fatal would take the whole app down over a missing seed.
     *
     * Emitted at most once per RESOLVE (never once per key), and only on the
     * cache-miss path -- resolveFresh() is where the catalog is actually consulted,
     * so a warm cache legitimately skips both the probe and the line rather than
     * spamming the log on every request.
     *
     * The logger is resolved DEFENSIVELY (the same pattern as
     * SubscriptionEventProjector::resolveLogger() and
     * MemberEntitlementResolver::resolveLogger()): a host with no logger binding
     * gets a no-op, never a fatal inside an entitlement check.
     */
    private function warnIfDefaultPlanUnresolvable(ApplicationContext $context, string $defaultPlan): void
    {
        if ($this->catalog->planExists($defaultPlan)) {
            return;
        }

        $this->resolveLogger($context)?->error(
            sprintf(
                'subscriptions: the platform plan catalog cannot resolve default_plan "%s" -- no matching '
                . "row in subscription_plans (audience='tenant', owner_tenant_uuid=''). Since 2.0 the "
                . 'catalog is database-authoritative and config("subscriptions.plans") is only a seed, so '
                . 'EVERY entitlement will resolve to an empty map until the catalog is imported: run '
                . '`php glueful subscriptions:plans:import-config`.',
                $defaultPlan
            ),
            [
                'event' => 'subscriptions.default_plan_unresolvable',
                'default_plan' => $defaultPlan,
                'command' => 'subscriptions:plans:import-config',
            ]
        );
    }

    /**
     * Resolved DEFENSIVELY (mirrors SubscriptionEventProjector::resolveLogger()):
     * this resolver must never hard-depend on a logging service.
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

    /**
     * Embeds the full subject triple (tenant_uuid, subject_type, subject_uuid) via
     * Subject::tenant(), not just the bare tenant uuid -- mirroring
     * MemberEntitlementResolver's member-path key (Subject::user()) so the two
     * paths structurally cannot collide, even under a coincidental
     * tenantUuid/userUuid match, without either resolver needing to know about
     * the other's cache entries.
     *
     * @param array<string,mixed>|null $subscription
     * @param array<string,mixed> $overrides
     */
    private function cacheKey(string $tenantUuid, ?array $subscription, array $overrides): string
    {
        $subject = Subject::tenant($tenantUuid);

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
