<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Plans;

use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * The five scope-aware host-facing methods on PlanManagementService
 * (createInScope/updateInScope/archiveInScope/findInScope/listInScope), plus the
 * cross-scope isolation the 2.0 activation added by turning the unqualified
 * create/update/archive/find/list into platform-scope delegates of these.
 */
final class ScopedPlanManagementServiceTest extends SubscriptionsTestCase
{
    private PlanManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlanManagementService(
            $this->appContext(),
            new SubscriptionPlanRepository(),
            new PlanPayloadValidator()
        );
    }

    /** @return array<string,mixed> */
    private function payload(string $planKey = 'starter'): array
    {
        return [
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'description' => 'Scoped plan',
            'entitlements' => ['projects.limit' => 5],
            'provider_price_id' => null,
            'status' => 'active',
            'sort_order' => 5,
        ];
    }

    public function testCreateInScopeWritesRowWithAudienceAndOwner(): void
    {
        $row = $this->service->createInScope('user', 'workspace-1', $this->payload('member'));

        self::assertSame('member', $row['plan_key']);
        self::assertSame('user', $row['audience']);
        self::assertSame('workspace-1', $row['owner_tenant_uuid']);

        $stored = $this->connection()->table('subscription_plans')
            ->where('plan_key', '=', 'member')
            ->where('audience', '=', 'user')
            ->where('owner_tenant_uuid', '=', 'workspace-1')
            ->first();
        self::assertIsArray($stored);
    }

    public function testCreateInScopeRejectsTenantAudienceWithNonEmptyOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->createInScope('tenant', 'workspace-1', $this->payload('member'));
    }

    public function testCreateInScopeRejectsUserAudienceWithEmptyOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->createInScope('user', '', $this->payload('member'));
    }

    public function testCreateInScopeRejectsUnknownAudience(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->createInScope('bogus', '', $this->payload('member'));
    }

    public function testCreateInScopeAllowsSameKeyAcrossDifferentScopesWithoutCollision(): void
    {
        // 'pro' already exists in the platform scope (seeded by SubscriptionsTestCase).
        $row = $this->service->createInScope('user', 'workspace-1', $this->payload('pro'));

        self::assertSame('pro', $row['plan_key']);
        self::assertNotSame(
            $this->service->find('pro')['uuid'] ?? null,
            $row['uuid']
        );
    }

    public function testCreateInScopeRejectsDuplicateKeyWithinSameScope(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('member'));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->createInScope('user', 'workspace-1', $this->payload('member'));
    }

    public function testFindInScopeIsolatesByScope(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('member'));

        self::assertNotNull($this->service->findInScope('user', 'workspace-1', 'member'));
        self::assertNull($this->service->findInScope('user', 'workspace-2', 'member'));
        self::assertNull($this->service->findInScope('tenant', '', 'member'));
    }

    public function testFindInScopeEnforcesScopeInvariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->findInScope('user', '', 'member');
    }

    public function testListInScopeIsolatesByScope(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('member'));
        $this->service->createInScope('user', 'workspace-2', $this->payload('member'));

        $workspace1 = $this->service->listInScope('user', 'workspace-1');

        self::assertCount(1, $workspace1);
        self::assertSame('member', $workspace1[0]['plan_key']);

        $platform = $this->service->listInScope('tenant', '');
        self::assertSame(['free', 'pro'], array_column($platform, 'plan_key'));
    }

    public function testListInScopeEnforcesScopeInvariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->listInScope('tenant', 'workspace-1');
    }

    public function testUpdateInScopeUpdatesOnlyTheScopedRow(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('pro'));

        $row = $this->service->updateInScope('user', 'workspace-1', 'pro', [
            'display_name' => 'Workspace Pro',
            'entitlements' => ['projects.limit' => 42],
        ]);

        self::assertSame('Workspace Pro', $row['display_name']);
        self::assertSame(['projects.limit' => 42], $row['entitlements']);

        // The platform 'pro' plan is untouched.
        $platformPro = $this->service->find('pro');
        self::assertSame('Pro', $platformPro['display_name']);
        self::assertSame(50, $platformPro['entitlements']['projects.limit']);
    }

    public function testUpdateInScopeRejectsPlanKeyChangeWithExactMessage(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('member'));

        try {
            $this->service->updateInScope('user', 'workspace-1', 'member', ['plan_key' => 'renamed']);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('plan_key is immutable', $e->getMessage());
        }
    }

    public function testUpdateInScopeAllowsNoOpPlanKeyInPayload(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('member'));

        $row = $this->service->updateInScope('user', 'workspace-1', 'member', [
            'plan_key' => 'member',
            'display_name' => 'Member Plus',
        ]);

        self::assertSame('Member Plus', $row['display_name']);
    }

    public function testUpdateInScopeEnforcesScopeInvariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->updateInScope('tenant', 'workspace-1', 'pro', ['display_name' => 'x']);
    }

    public function testArchiveInScopeArchivesOnlyTheScopedRow(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('pro'));

        $row = $this->service->archiveInScope('user', 'workspace-1', 'pro');

        self::assertSame('archived', $row['status']);
        self::assertSame('active', $this->service->find('pro')['status']);
    }

    public function testArchiveInScopeEnforcesScopeInvariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->archiveInScope('bogus', '', 'pro');
    }

    // ---------------------------------------------------------------
    // Cross-scope isolation (regression, carried finding from Task 8)
    // ---------------------------------------------------------------

    /**
     * REGRESSION. Before the 2.0 activation the unqualified update() reached the
     * unscoped `updateByKey()` -- `WHERE plan_key = ?` with no scope predicate.
     * Migration 006 had already replaced UNIQUE(plan_key) with
     * UNIQUE(audience, owner_tenant_uuid, plan_key), so a platform-scope PATCH of
     * 'pro' silently mutated a same-keyed WORKSPACE plan too. Now update() is
     * updateInScope('tenant', '', ...) and cannot reach across scopes.
     */
    public function testPlatformUpdateLeavesASameKeyedWorkspacePlanUntouched(): void
    {
        // Platform 'pro' comes from the harness's seeded catalog.
        $workspace = $this->service->createInScope('user', 'workspace-1', $this->payload('pro'));

        $this->service->update('pro', ['display_name' => 'Platform Pro', 'status' => 'archived']);

        $platformRow = $this->service->findInScope('tenant', '', 'pro');
        self::assertSame('Platform Pro', $platformRow['display_name']);
        self::assertSame('archived', $platformRow['status']);

        $workspaceRow = $this->service->findInScope('user', 'workspace-1', 'pro');
        self::assertSame($workspace['uuid'], $workspaceRow['uuid']);
        self::assertSame('Pro', $workspaceRow['display_name']);
        self::assertSame('active', $workspaceRow['status']);
        self::assertSame(['projects.limit' => 5], $workspaceRow['entitlements']);
    }

    public function testPlatformArchiveLeavesASameKeyedWorkspacePlanUntouched(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('pro'));

        $this->service->archive('pro');

        self::assertSame('archived', $this->service->findInScope('tenant', '', 'pro')['status']);
        self::assertSame('active', $this->service->findInScope('user', 'workspace-1', 'pro')['status']);
    }

    public function testPlatformReadsNeverSeeWorkspacePlans(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('members-only'));

        self::assertNull($this->service->find('members-only'));
        self::assertNotContains('members-only', array_column($this->service->list(), 'plan_key'));
    }

    public function testPlatformCreateIsNotBlockedByASameKeyedWorkspacePlan(): void
    {
        $this->service->createInScope('user', 'workspace-1', $this->payload('growth'));

        $row = $this->service->create($this->payload('growth'));

        self::assertSame('tenant', $row['audience']);
        self::assertSame('', $row['owner_tenant_uuid']);
    }
}
