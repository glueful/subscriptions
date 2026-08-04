<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Resolution;

use Glueful\Cache\CacheStore;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\Resolution\EffectivePlanResolver;
use Glueful\Extensions\Subscriptions\Resolution\EntitlementResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\CapturingLogger;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

final class EntitlementResolverTest extends SubscriptionsTestCase
{
    private const FREE = ['reports.export' => false, 'projects.limit' => 3, 'team.limit' => 1];
    private const PRO = [
        'reports.export' => true,
        'projects.limit' => 50,
        'team.limit' => 20,
        'api.monthly' => 100000,
    ];

    /**
     * The DB-authoritative platform catalog. The harness seeds 'free'/'pro' from
     * config/subscriptions.php, so the FREE/PRO constants above still describe the
     * catalog exactly -- what changed is that they now come from real plan rows
     * rather than a config overlay.
     */
    private function catalog(): PlanCatalog
    {
        return PlanCatalog::fromContext($this->appContext());
    }

    private function resolver(?CacheStore $cache = null, bool $cacheEnabled = false): EntitlementResolver
    {
        return new EntitlementResolver(
            $this->catalog(),
            new SubscriptionRepository(),
            new OverrideRepository(),
            new EffectivePlanResolver(),
            $cache,
            $cacheEnabled,
            300
        );
    }

    /** @param array<string,mixed> $row */
    private function seedOverride(array $row): void
    {
        $this->connection()->table('subscription_overrides')->insert(array_merge([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'tenant',
            'subject_uuid' => 'tenantA',
            'expires_at' => null,
        ], $row, ['value' => json_encode($row['value'], JSON_THROW_ON_ERROR)]));
    }

    public function testActiveProTenantResolvesProEntitlements(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);

        self::assertSame(self::PRO, $this->resolver()->resolveMap($this->appContext(), 'tenantA'));
    }

    public function testCanceledProTenantDowngradesToDefaultEntitlements(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'canceled']);

        self::assertSame(self::FREE, $this->resolver()->resolveMap($this->appContext(), 'tenantA'));
    }

    public function testTenantWithNoSubscriptionResolvesDefaultEntitlements(): void
    {
        self::assertSame(self::FREE, $this->resolver()->resolveMap($this->appContext(), 'ghost'));
    }

    /**
     * Task 10 (design spec §4.1): an `incomplete` row -- the shape
     * `SubscriptionService::reserveCheckoutFor()` creates -- is NON-ENTITLING. It
     * must resolve identically to `canceled`/no-subscription, never to the plan it
     * is reserved against, even though `plan_key` on the row is already 'pro'.
     */
    public function testIncompleteProReservationDowngradesToDefaultEntitlements(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'incomplete']);

        self::assertSame(self::FREE, $this->resolver()->resolveMap($this->appContext(), 'tenantA'));
    }

    public function testActiveOverrideWinsPerKeyAndExpiredOverrideIsIgnored(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);
        $this->seedOverride(['entitlement' => 'projects.limit', 'value' => 999]);
        $this->seedOverride([
            'entitlement' => 'reports.export',
            'value' => false,
            'expires_at' => '2020-01-01 00:00:00',
        ]);

        $map = $this->resolver()->resolveMap($this->appContext(), 'tenantA');

        self::assertSame(999, $map['projects.limit']);
        self::assertTrue($map['reports.export']); // expired override did NOT flip it
    }

    public function testOverrideAddsBrandNewKey(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free', 'status' => 'active']);
        $this->seedOverride(['entitlement' => 'beta.feature', 'value' => true]);

        $map = $this->resolver()->resolveMap($this->appContext(), 'tenantA');

        self::assertTrue($map['beta.feature']);
        self::assertFalse($map['reports.export']);
    }

    public function testCacheKeyComposesTenantAndCatalogVersion(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);

        $cache = $this->createMock(CacheStore::class);
        $cache->expects(self::once())
            ->method('remember')
            ->with(
                // Embeds the full subject triple (tenant_uuid, subject_type,
                // subject_uuid) via Subject::tenant(), not just the bare tenant
                // uuid -- so the key structurally cannot collide with a member
                // subject's cache entry even under a coincidental uuid match.
                self::stringStartsWith(
                    'subscriptions.ent:tenantA:tenant:tenantA:' . $this->catalog()->version() . ':'
                ),
                self::isInstanceOf(\Closure::class),
                300
            )
            ->willReturnCallback(static fn (string $key, callable $callback, ?int $ttl): mixed => $callback());

        $map = $this->resolver($cache, true)->resolveMap($this->appContext(), 'tenantA');

        self::assertSame(self::PRO, $map);
    }

    /**
     * The key must change across every security-relevant transition even when
     * updated_at is untouched: status downgrade, plan change, override
     * add/remove/expire, and catalog version bump.
     */
    public function testCacheKeyChangesOnStatusDowngrade(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);
        $active = $this->capturedKey();

        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', 'tenantA')
            ->update(['status' => 'canceled']);
        $canceled = $this->capturedKey();

        self::assertNotSame($active, $canceled);
    }

    public function testCacheKeyChangesOnPlanChange(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);
        $pro = $this->capturedKey();

        $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', 'tenantA')
            ->update(['plan_key' => 'free']);
        $free = $this->capturedKey();

        self::assertNotSame($pro, $free);
    }

    public function testCacheKeyChangesWhenOverrideAddedThenRemoved(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);
        $base = $this->capturedKey();

        $this->seedOverride(['entitlement' => 'projects.limit', 'value' => 999]);
        $withOverride = $this->capturedKey();
        self::assertNotSame($base, $withOverride);

        $this->connection()->table('subscription_overrides')
            ->where('tenant_uuid', '=', 'tenantA')
            ->delete();
        $afterRemoval = $this->capturedKey();

        self::assertNotSame($withOverride, $afterRemoval);
        self::assertSame($base, $afterRemoval);
    }

    public function testCacheKeyChangesWhenOverrideExpires(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);
        $this->seedOverride([
            'entitlement' => 'projects.limit',
            'value' => 999,
            'expires_at' => '2999-01-01 00:00:00',
        ]);
        $active = $this->capturedKey();

        $this->connection()->table('subscription_overrides')
            ->where('tenant_uuid', '=', 'tenantA')
            ->update(['expires_at' => '2020-01-01 00:00:00']);
        $expired = $this->capturedKey();

        self::assertNotSame($active, $expired);
    }

    /** Resolve once through a recording cache and return the key it was asked for. */
    private function capturedKey(): string
    {
        $captured = null;
        $cache = $this->createMock(CacheStore::class);
        $cache->method('remember')
            ->willReturnCallback(
                static function (string $key, callable $callback, ?int $ttl) use (&$captured): mixed {
                    $captured = $key;
                    return $callback();
                }
            );

        $this->resolver($cache, true)->resolveMap($this->appContext(), 'tenantA');

        self::assertIsString($captured);

        return $captured;
    }

    public function testResolvesUncachedWhenCacheStoreIsNull(): void
    {
        // B3: CacheStore may be unbound in a zero-infra install -- enabled flag alone
        // must not break resolution.
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);

        $resolver = $this->resolver(null, true);

        self::assertSame(self::PRO, $resolver->resolveMap($this->appContext(), 'tenantA'));
    }

    // ===========================================
    // C1 -- the spec §3.3 fresh-install diagnostic
    // ===========================================

    /**
     * A fresh 2.0 install that runs `migrate:run` but never
     * `subscriptions:plans:import-config` has an EMPTY subscription_plans table.
     * Because the catalog is DB-authoritative since 2.0 (config plans are seeds,
     * not a runtime overlay), every entitlement then resolves to `[]` -- an install
     * that is silently non-functional and looks exactly like "no entitlements".
     * The resolver must say so, loudly, once per resolve -- and must NOT throw:
     * entitlement checks stay fail-closed-not-fatal.
     */
    public function testFreshInstallWithNoPlatformPlansLogsTheImportDiagnosticAndResolvesEmpty(): void
    {
        $this->clearPlatformPlans(); // the fresh-install shape: no platform plan rows at all
        $logger = new CapturingLogger();
        $this->bind('logger', $logger);

        $map = $this->resolver()->resolveMap($this->appContext(), 'tenantA');

        self::assertSame([], $map);

        $errors = array_values(array_filter(
            $logger->records(),
            static fn (array $r): bool => ($r['context']['event'] ?? null) === 'subscriptions.default_plan_unresolvable'
        ));
        self::assertCount(1, $errors, 'exactly one diagnostic per resolve');
        self::assertSame('error', $errors[0]['level']);
        self::assertStringContainsString('subscriptions:plans:import-config', $errors[0]['message']);
        self::assertStringContainsString('free', $errors[0]['message']); // names the missing default_plan key
        self::assertSame('free', $errors[0]['context']['default_plan']);
        self::assertSame('subscriptions:plans:import-config', $errors[0]['context']['command']);
    }

    public function testTheFreshInstallDiagnosticIsNotEmittedWhenTheDefaultPlanResolves(): void
    {
        $logger = new CapturingLogger();
        $this->bind('logger', $logger);

        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);
        self::assertSame(self::PRO, $this->resolver()->resolveMap($this->appContext(), 'tenantA'));

        self::assertSame([], array_values(array_filter(
            $logger->records(),
            static fn (array $r): bool => ($r['context']['event'] ?? null) === 'subscriptions.default_plan_unresolvable'
        )));
    }

    public function testTheFreshInstallDiagnosticNeverThrowsWithNoLoggerBound(): void
    {
        $this->clearPlatformPlans();

        // No 'logger' binding at all -- the defensive resolve must degrade to a no-op.
        self::assertSame([], $this->resolver()->resolveMap($this->appContext(), 'tenantA'));
    }

    /**
     * C2: the shipped writer's output must be honoured by the tenant resolver --
     * this is the end-to-end proof that OverrideRepository::upsertForSubject() is a
     * safe replacement for the direct 1.x-shaped insert (which post-006 leaves
     * subject_uuid at `''` and is silently ignored, turning a deny into a grant).
     */
    public function testAnOverrideWrittenByUpsertForSubjectIsHonouredByTheTenantResolver(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);

        $overrides = new OverrideRepository();
        $overrides->upsertForSubject($this->appContext(), Subject::tenant('tenantA'), 'reports.export', false);
        $overrides->upsertForSubject($this->appContext(), Subject::tenant('tenantA'), 'projects.limit', 5);

        $map = $this->resolver()->resolveMap($this->appContext(), 'tenantA');

        self::assertFalse($map['reports.export'], 'a deny override must actually DENY');
        self::assertSame(5, $map['projects.limit']);

        $overrides->deleteForSubject($this->appContext(), Subject::tenant('tenantA'), 'reports.export');

        self::assertTrue($this->resolver()->resolveMap($this->appContext(), 'tenantA')['reports.export']);
    }
}
