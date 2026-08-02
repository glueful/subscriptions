<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\SubjectType;

/**
 * Builds a {@see MemberEntitlementResolver} scoped to an explicit workspace,
 * strictly ON DEMAND -- never at DI-container-resolution time.
 *
 * Why this exists (coordinator-flagged Critical on Task 14): a
 * MemberEntitlementResolver's ctor-injected PlanCatalog must already be
 * scoped to the EXACT workspace `resolveMap()` will be called with (Task 11's
 * `assertCatalogScopeMatches()` guard throws otherwise). The current tenant is
 * only known at request/middleware-*handle*-time -- `Router::executeWithMiddleware()`
 * resolves EVERY middleware object from the container in one pre-pass BEFORE
 * any of their `handle()` methods run. On an ordinary stack like
 * `['tenant', 'require_member_entitlement:x']`, a route-level tenancy
 * middleware ordered earlier has NOT yet run its `handle()` (and so has not
 * yet set the tenant request-state) at the moment the container builds the
 * `require_member_entitlement` middleware object during that same pre-pass.
 * An earlier design baked `SubjectResolverInterface::currentTenant()` into a
 * container factory for `MemberEntitlementResolver` directly -- that read
 * null/empty at pre-pass time, silently scoping the catalog to `('user', '')`;
 * by the time `handle()` re-read `currentTenant()` (now correctly non-null,
 * since the tenancy middleware's `handle()` had run by then) and called
 * `resolveMap()`, the scope guard threw `InvalidArgumentException` -- an
 * uncaught 500, not the intended fail-closed 403.
 *
 * The fix: this factory is a shared, STATELESS service. It holds only the
 * dependencies that do not vary per call (repositories, the effective plan
 * resolver, the optional cache -- all constant for the life of the
 * application, never tenant-scoped) and builds a freshly-scoped
 * `PlanCatalog` + `MemberEntitlementResolver` on every {@see forWorkspace()}
 * call, using a caller-supplied `$tenantUuid` the caller resolved itself, at
 * the exact point it needs it. `RequireMemberEntitlement::handle()` calls
 * this AFTER its own `currentTenant()` read, inside `handle()` -- never
 * during DI resolution -- so the same tenant value drives both the scope and
 * the `resolveMap()` call, and no skew between "container build time" and
 * "request handling time" is structurally possible.
 */
final class MemberEntitlementResolverFactory
{
    /** @param CacheStore<mixed>|null $cache */
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly OverrideRepository $overrides,
        private readonly EffectivePlanResolver $planResolver,
        private readonly ?CacheStore $cache = null,
        private readonly bool $cacheEnabled = true,
        private readonly int $cacheTtl = 300,
    ) {
    }

    public function forWorkspace(ApplicationContext $context, string $tenantUuid): MemberEntitlementResolver
    {
        return new MemberEntitlementResolver(
            PlanCatalog::forScope($context, SubjectType::USER, $tenantUuid),
            $this->subscriptions,
            $this->overrides,
            $this->planResolver,
            $this->cache,
            $this->cacheEnabled,
            $this->cacheTtl,
        );
    }
}
