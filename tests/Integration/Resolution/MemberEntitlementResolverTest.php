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
use Glueful\Extensions\Subscriptions\Subject;
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

    /**
     * The ctor-injected catalog is pinned to ONE workspace (self::TENANT). Calling
     * resolveMap() with a DIFFERENT tenantUuid must never silently resolve that
     * other tenant's membership against THIS catalog's plans -- concretely: both
     * workspaces define their own same-named 'pro' plan here, and workspace B's
     * member must never receive workspace A's 'pro' entitlements just because A's
     * catalog happened to be the one injected.
     */
    public function testResolveMapThrowsWhenTenantUuidDoesNotMatchCatalogScope(): void
    {
        $this->seedWorkspacePlan('pro', ['content.premium' => true]); // workspace A's 'pro'

        $this->connection()->table('subscription_plans')->insert([
            'uuid' => 'planworkspacebp',
            'plan_key' => 'pro',
            'display_name' => 'Pro',
            'entitlements' => json_encode(['content.premium' => false], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => 'tenantB',
        ]);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantB',
            'subject_type' => 'user',
            'subject_uuid' => 'userB',
            'plan_key' => 'pro',
            'status' => 'active',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        // $this->resolver() injects a catalog scoped to self::TENANT ('tenantA');
        // calling it for 'tenantB' must refuse rather than resolve 'tenantB's
        // membership against 'tenantA's catalog.
        $this->resolver()->resolveMap($this->appContext(), 'tenantB', 'userB');
    }

    public function testResolveMapDoesNotThrowWhenTenantUuidMatchesCatalogScope(): void
    {
        $this->seedWorkspacePlan('member-pro', ['content.premium' => true]);
        $this->seedMembership(['plan_key' => 'member-pro']);

        $map = $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER);

        self::assertSame(['content.premium' => true], $map);
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

    /**
     * Nothing normalizes entitlement keys anywhere in the system (config and
     * plan-JSON paths are both unvalidated), so the strip must not be a bare
     * case-sensitive prefix match -- a mixed-case or padded variant of
     * `rate.tier.*` must be caught exactly the same as the canonical form.
     */
    public function testRateTierKeyIsStrippedRegardlessOfCaseAndPadding(): void
    {
        $this->seedWorkspacePlan('member-pro', [
            'content.premium' => true,
            'Rate.Tier.Pro' => true,
            '  rate.tier.enterprise  ' => true,
        ]);
        $this->seedMembership(['plan_key' => 'member-pro']);

        $map = $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER);

        self::assertSame(['content.premium' => true], $map);
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

    /** C2: the shipped writer's output must be honoured by the member resolver too. */
    public function testAnOverrideWrittenByUpsertForSubjectIsHonouredByTheMemberResolver(): void
    {
        $this->seedWorkspacePlan('pro', ['content.premium' => true, 'downloads.limit' => 3]);
        $this->seedMembership(['plan_key' => 'pro']);

        $overrides = new OverrideRepository();
        $subject = Subject::user(self::TENANT, self::USER);
        $overrides->upsertForSubject($this->appContext(), $subject, 'content.premium', false);
        $overrides->upsertForSubject($this->appContext(), $subject, 'downloads.limit', 99);

        $map = $this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER);

        self::assertFalse($map['content.premium'], 'a deny override must actually DENY');
        self::assertSame(99, $map['downloads.limit']);

        $overrides->deleteForSubject($this->appContext(), $subject, 'content.premium');

        self::assertTrue($this->resolver()->resolveMap($this->appContext(), self::TENANT, self::USER)['content.premium']);
    }
}
