<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Resolution;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\Resolution\MemberEntitlementResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Task 11: the workspace-member entitlement path -- Subject::user($tenantUuid,
 * $userUuid), a WORKSPACE-scoped PlanCatalog (audience='user',
 * owner_tenant_uuid=$tenantUuid), and an empty-string default plan (no implicit
 * "free" for a member -- absent membership is simply an empty base map).
 */
final class MemberEntitlementResolverTest extends SubscriptionsTestCase
{
    private const TENANT = 'tenantA';
    private const USER = 'userA';

    private function catalog(): PlanCatalog
    {
        return PlanCatalog::forScope($this->appContext(), 'user', self::TENANT);
    }

    private function resolver(?CacheStore $cache = null, bool $cacheEnabled = false): MemberEntitlementResolver
    {
        return new MemberEntitlementResolver(
            $this->catalog(),
            new SubscriptionRepository(),
            new OverrideRepository(),
            new EffectivePlanResolver(),
            $cache,
            $cacheEnabled,
            300
        );
    }

    /** @param array<string,mixed> $entitlements */
    private function seedWorkspacePlan(string $planKey, array $entitlements): void
    {
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => str_pad('planmember' . $planKey, 12, '0'),
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'entitlements' => json_encode($entitlements, JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => self::TENANT,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function seedMembership(array $overrides = []): void
    {
        $this->seedSubscription(array_merge([
            'tenant_uuid' => self::TENANT,
            'subject_type' => 'user',
            'subject_uuid' => self::USER,
            'status' => 'active',
        ], $overrides));
    }

    /** @param array<string,mixed> $row */
    private function seedOverride(array $row): void
    {
        $this->connection()->table('subscription_overrides')->insert(array_merge([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => self::TENANT,
            'subject_type' => 'user',
            'subject_uuid' => self::USER,
            'expires_at' => null,
        ], $row, ['value' => json_encode($row['value'], JSON_THROW_ON_ERROR)]));
    }

    public function testNoMembershipAndNoOverrideResolvesEmptyMap(): void
    {
        self::assertSame(
            [],
            $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER)
        );
    }

    public function testNoMembershipWithActiveOverrideResolvesExactlyThatOverrideMap(): void
    {
        $this->seedOverride(['entitlement' => 'content.premium', 'value' => true]);

        $map = $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER);

        self::assertSame(['content.premium' => true], $map);
    }

    public function testExpiredOverrideWithNoMembershipResolvesEmptyMap(): void
    {
        $this->seedOverride([
            'entitlement' => 'content.premium',
            'value' => true,
            'expires_at' => '2020-01-01 00:00:00',
        ]);

        self::assertSame(
            [],
            $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER)
        );
    }

    public function testMembershipRowResolvesWorkspacePlanEntitlements(): void
    {
        $this->seedWorkspacePlan('member-pro', ['content.premium' => true, 'projects.limit' => 10]);
        $this->seedMembership(['plan_key' => 'member-pro']);

        $map = $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER);

        self::assertSame(['content.premium' => true, 'projects.limit' => 10], $map);
    }

    public function testCanceledMembershipResolvesEmptyBaseMap(): void
    {
        // Empty-string default plan: a lapsed membership has no implicit fallback
        // plan (unlike the tenant path's 'free') -- it downgrades to NO plan, i.e.
        // an empty base map.
        $this->seedWorkspacePlan('member-pro', ['content.premium' => true]);
        $this->seedMembership(['plan_key' => 'member-pro', 'status' => 'canceled']);

        self::assertSame(
            [],
            $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER)
        );
    }

    public function testMembershipPlusOverrideMergesWithOverrideWinning(): void
    {
        $this->seedWorkspacePlan('member-pro', ['content.premium' => false, 'projects.limit' => 10]);
        $this->seedMembership(['plan_key' => 'member-pro']);
        $this->seedOverride(['entitlement' => 'content.premium', 'value' => true]);

        $map = $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER);

        self::assertTrue($map['content.premium']);
        self::assertSame(10, $map['projects.limit']);
    }

    public function testRateTierKeyIsStrippedFromMemberOutput(): void
    {
        $this->seedWorkspacePlan('member-pro', [
            'content.premium' => true,
            'rate.tier.pro' => true,
        ]);
        $this->seedMembership(['plan_key' => 'member-pro']);

        $map = $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER);

        self::assertArrayNotHasKey('rate.tier.pro', $map);
        self::assertTrue($map['content.premium']);
    }

    public function testRateTierStrippingIsLoggedExactlyOncePerResolve(): void
    {
        $this->seedWorkspacePlan('member-pro', [
            'content.premium' => true,
            'rate.tier.pro' => true,
            'rate.tier.enterprise' => false,
        ]);
        $this->seedMembership(['plan_key' => 'member-pro']);

        $logger = new class extends NullLogger implements LoggerInterface {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $this->bind('logger', $logger);
        $this->bind(LoggerInterface::class, $logger);

        $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER);

        // Two rate.tier.* keys were stripped, but the log fired exactly once for
        // the whole resolve, not once per stripped key.
        self::assertCount(1, $logger->messages);
    }

    public function testNoRateTierKeysProducesNoLogCall(): void
    {
        $this->seedWorkspacePlan('member-pro', ['content.premium' => true]);
        $this->seedMembership(['plan_key' => 'member-pro']);

        $logger = new class extends NullLogger implements LoggerInterface {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $this->bind('logger', $logger);
        $this->bind(LoggerInterface::class, $logger);

        $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER);

        self::assertCount(0, $logger->messages);
    }

    /**
     * Isolation (spec §11.6): resolving member fixtures for a user subject on
     * tenantA must not perturb the tenant path's own resolution for tenantA --
     * two entry points, never crossed.
     */
    public function testTenantResolverOutputUnchangedByMemberFixtures(): void
    {
        $tenantResolver = new EntitlementResolver(
            PlanCatalog::fromContext($this->appContext()),
            new SubscriptionRepository(),
            new OverrideRepository(),
            new EffectivePlanResolver(),
            null,
            false,
            300
        );

        $before = $tenantResolver->resolveMap($this->appContext(), self::TENANT);

        $this->seedWorkspacePlan('member-pro', ['content.premium' => true, 'rate.tier.pro' => true]);
        $this->seedMembership(['plan_key' => 'member-pro']);
        $this->seedOverride(['entitlement' => 'content.premium', 'value' => true]);

        $after = $tenantResolver->resolveMap($this->appContext(), self::TENANT);

        self::assertSame($before, $after);
    }
}
