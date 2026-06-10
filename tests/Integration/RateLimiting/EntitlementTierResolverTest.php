<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\RateLimiting;

use Glueful\Api\RateLimiting\TierManager;
use Glueful\Api\RateLimiting\TierResolver;
use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Extensions\Subscriptions\RateLimiting\EntitlementTierResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EntitlementTierResolverTest extends SubscriptionsTestCase
{
    /** @param array<string,bool> $grants entitlement => allowed */
    private function checker(array $grants): EntitlementCheckerInterface
    {
        return new class ($grants) implements EntitlementCheckerInterface {
            /** @param array<string,bool> $grants */
            public function __construct(private readonly array $grants)
            {
            }

            public function allows(string $tenantUuid, string $entitlement, array $context = []): bool
            {
                return $this->grants[$entitlement] ?? false;
            }

            public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int
            {
                return null;
            }
        };
    }

    private function throwingChecker(): EntitlementCheckerInterface
    {
        return new class implements EntitlementCheckerInterface {
            public function allows(string $tenantUuid, string $entitlement, array $context = []): bool
            {
                throw new \RuntimeException('entitlement backend down');
            }

            public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int
            {
                throw new \RuntimeException('entitlement backend down');
            }
        };
    }

    private function resolver(EntitlementCheckerInterface $checker): EntitlementTierResolver
    {
        // The wrapped framework default: no 'user' request attribute -> 'anonymous'.
        $inner = new TierResolver(new TierManager([]));

        return new EntitlementTierResolver($checker, $this->appContext(), $inner);
    }

    private function setTenant(string $uuid): void
    {
        $this->appContext()->setRequestState('tenancy.tenant', new class ($uuid) {
            public function __construct(public string $uuid)
            {
            }
        });
    }

    public function testNoTenantDelegatesToWrappedDefaultResolver(): void
    {
        // Proves the bridge is inert without tenancy: no tenant resolves, so the
        // wrapped default tiering answers.
        $resolver = $this->resolver($this->checker(['rate.tier.pro' => true]));

        self::assertSame('anonymous', $resolver->resolve(Request::create('/api')));
    }

    public function testTierFlagMappingReturnsHighestGrantedTier(): void
    {
        $this->setTenant('tenantA');
        $resolver = $this->resolver($this->checker([
            'rate.tier.pro' => true,
            'rate.tier.enterprise' => false,
        ]));

        self::assertSame('pro', $resolver->resolve(Request::create('/api')));
    }

    public function testFirstConfiguredTierWinsWhenMultipleGranted(): void
    {
        // Config order is ['enterprise', 'pro'] (highest first): a tenant
        // granted BOTH flags must land in the enterprise bucket.
        $this->setTenant('tenantA');
        $resolver = $this->resolver($this->checker([
            'rate.tier.enterprise' => true,
            'rate.tier.pro' => true,
        ]));

        self::assertSame('enterprise', $resolver->resolve(Request::create('/api')));
    }

    public function testNoGrantedTierDelegatesToWrappedDefault(): void
    {
        $this->setTenant('tenantA');
        $resolver = $this->resolver($this->checker([]));

        self::assertSame('anonymous', $resolver->resolve(Request::create('/api')));
    }

    public function testCheckerFailureDegradesToWrappedDefault(): void
    {
        // An entitlement read must never hard-fail rate limiting.
        $this->setTenant('tenantA');
        $resolver = $this->resolver($this->throwingChecker());

        self::assertSame('anonymous', $resolver->resolve(Request::create('/api')));
    }
}
