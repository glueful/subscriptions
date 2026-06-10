<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\RateLimiting;

use Glueful\Api\RateLimiting\Contracts\TierResolverInterface;
use Glueful\Api\RateLimiting\TierResolver;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Extensions\Subscriptions\Tenant\CurrentTenant;
use Symfony\Component\HttpFoundation\Request;

final class EntitlementTierResolver implements TierResolverInterface
{
    public function __construct(
        private readonly EntitlementCheckerInterface $checker,
        private readonly ApplicationContext $context,
        private readonly TierResolver $inner,
    ) {
    }

    public function resolve(Request $request): string
    {
        try {
            $tenantUuid = CurrentTenant::resolve($this->context);
            if ($tenantUuid === null) {
                return $this->inner->resolve($request);
            }

            foreach ((array) config($this->context, 'subscriptions.rate_tiers', []) as $tier) {
                if (!is_scalar($tier)) {
                    continue;
                }
                $tier = (string) $tier;
                if ($this->checker->allows($tenantUuid, "rate.tier.{$tier}")) {
                    return $tier;
                }
            }
        } catch (\Throwable) {
            return $this->inner->resolve($request);
        }

        return $this->inner->resolve($request);
    }
}
