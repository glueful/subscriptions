<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\RecordingTenantContextRunner;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

/**
 * Task 1 (thallo-subscriptions program, 2.1.0 seam) -- the bulk trusted
 * administrative projection (design spec §6.1): `SubscriptionService::
 * currentForTenants()` reads across an arbitrary set of tenants in ONE query,
 * keyed output with absent keys for misses, a hard MAX_TENANT_BATCH=100
 * bound checked before any query, tenant-subject-only visibility, and the
 * `TenantIntegration::runAsSystemOr()` wrap that keeps tenancy interception
 * from narrowing an administrative read down to a single ambient tenant.
 */
final class SubscriptionServiceBulkReadTest extends SubscriptionsTestCase
{
    protected SubscriptionService $service;

    /** @var SpySubscriptionRepository|null */
    protected ?SpySubscriptionRepository $spyRepo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->buildService(new SubscriptionRepository());
    }

    private function buildService(SubscriptionRepository $repository): SubscriptionService
    {
        return new SubscriptionService(
            $repository,
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            new DefaultSubjectResolver(),
        );
    }

    private function service(): SubscriptionService
    {
        return $this->service;
    }

    private function serviceWithSpyRepo(): SubscriptionService
    {
        $this->spyRepo = new SpySubscriptionRepository();

        return $this->buildService($this->spyRepo);
    }

    /** Plan row already exists in the seeded platform catalog ('free'/'pro'); this seeds the subject row. */
    private function seedPlatformPlanAndSubscription(string $tenantUuid, string $planKey): array
    {
        return $this->seedSubscription(['tenant_uuid' => $tenantUuid, 'plan_key' => $planKey]);
    }

    /** Seeds a USER-subject membership row -- a bulk tenant read must never surface it. */
    private function seedMembership(string $tenantUuid, string $userUuid, string $planKey): array
    {
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => Utils::generateNanoID(12),
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'entitlements' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => $tenantUuid,
        ]);

        return $this->seedSubscription([
            'tenant_uuid' => $tenantUuid,
            'subject_type' => 'user',
            'subject_uuid' => $userUuid,
            'plan_key' => $planKey,
        ]);
    }

    /** @return list<string> */
    private function tenantIds(int $n): array
    {
        return array_map(static fn (int $i): string => "t-{$i}", range(1, $n));
    }

    // ---------------------------------------------------------------

    public function testReturnsRowsKeyedByTenantUuidWithAbsentKeysForMisses(): void
    {
        $this->seedPlatformPlanAndSubscription('t-1', 'pro');
        $this->seedPlatformPlanAndSubscription('t-3', 'free');

        $out = $this->service->currentForTenants(['t-1', 't-2', 't-3']);

        self::assertSame(['t-1', 't-3'], array_keys($out));
        self::assertSame('pro', $out['t-1']['plan_key']);
    }

    public function testEmptyListReturnsEmptyArrayWithoutQuerying(): void
    {
        self::assertSame([], $this->serviceWithSpyRepo()->currentForTenants([]));
        self::assertSame(0, $this->spyRepo->calls);
    }

    public function testNeverReturnsUserSubjectRows(): void
    {
        $this->seedMembership('t-1', 'u-1', 'member-basic');

        self::assertSame([], $this->service->currentForTenants(['t-1']));
    }

    public function testConstantRepositoryCallCountAcrossPageSizes(): void
    {
        foreach ([1, 25, 100] as $n) {
            $this->spyRepo = null;
            $service = $this->serviceWithSpyRepo();
            $service->currentForTenants($this->tenantIds($n));
            self::assertSame(1, $this->spyRepo->calls, "page size {$n}");
        }
    }

    public function testRejectsMoreThanOneHundredInputsBeforeQuerying(): void
    {
        $service = $this->serviceWithSpyRepo();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->currentForTenants($this->tenantIds(101));
        } finally {
            self::assertSame(0, $this->spyRepo->calls);
        }
    }

    public function testRunsTheCrossWorkspaceReadAsSystem(): void
    {
        $runner = new RecordingTenantContextRunner();
        $this->bind(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class, $runner);
        $this->seedPlatformPlanAndSubscription('t-1', 'pro');
        $this->seedPlatformPlanAndSubscription('t-2', 'pro');

        $rows = $this->service()->currentForTenants(['t-1', 't-2']);

        self::assertSame(['t-1', 't-2'], array_keys($rows));
        self::assertSame([['mode' => 'system', 'tenantUuid' => null]], $runner->calls());
    }

    public function testInputIsNormalizedAndDeduplicated(): void
    {
        $this->seedPlatformPlanAndSubscription('t-1', 'pro');

        $out = $this->service->currentForTenants(['t-1', 't-1', '', ' t-1 ']);

        self::assertSame(['t-1'], array_keys($out));
    }
}

/**
 * Proves the repository-call count: increments a counter in
 * findTenantSubjectsAmong() then delegates to the real implementation, so the
 * spy pins ONE repository call per currentForTenants() invocation regardless
 * of batch size (the repository method itself is single-query by
 * construction -- one `whereIn` call, no loop).
 */
final class SpySubscriptionRepository extends SubscriptionRepository
{
    public int $calls = 0;

    public function findTenantSubjectsAmong(ApplicationContext $context, array $tenantUuids): array
    {
        $this->calls++;

        return parent::findTenantSubjectsAmong($context, $tenantUuids);
    }
}
