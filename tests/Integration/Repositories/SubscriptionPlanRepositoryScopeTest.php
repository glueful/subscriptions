<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

/**
 * Every SubscriptionPlanRepository finder gains a scope-aware sibling
 * (findByUuid, findByKeyInScope, findResolvableByKeyInScope, listInScope,
 * maxUpdatedAtInScope) added alongside the byte-compatible 1.x names, which
 * are now pinned to the platform scope ('tenant', '') since the coordinated
 * activation.
 */
final class SubscriptionPlanRepositoryScopeTest extends SubscriptionsTestCase
{
    private SubscriptionPlanRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SubscriptionPlanRepository();
    }

    /** @param array<string,mixed> $overrides */
    private function plan(array $overrides = []): array
    {
        return array_merge([
            'uuid' => Utils::generateNanoID(12),
            'plan_key' => 'scoped',
            'display_name' => 'Scoped',
            'entitlements' => ['x' => true],
            'status' => 'active',
            'sort_order' => 10,
            'audience' => 'tenant',
            'owner_tenant_uuid' => '',
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
        ], $overrides);
    }

    public function testFindByUuidReturnsTheDecodedRowOrNull(): void
    {
        $this->repo->insert($this->appContext(), $this->plan(['uuid' => 'plan_find_01', 'plan_key' => 'find-me']));

        $row = $this->repo->findByUuid($this->appContext(), 'plan_find_01');

        self::assertIsArray($row);
        self::assertSame('find-me', $row['plan_key']);
        self::assertSame(['x' => true], $row['entitlements']);
        self::assertNull($this->repo->findByUuid($this->appContext(), 'ghost'));
    }

    public function testFindByKeyInScopeIsScopedByAudienceAndOwner(): void
    {
        // Platform plan 'pro' (seeded by the harness) lives at (tenant, '').
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_ws1_pro',
            'plan_key' => 'pro',
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-1',
        ]));

        $platform = $this->repo->findByKeyInScope($this->appContext(), 'tenant', '', 'pro');
        $workspaceOwned = $this->repo->findByKeyInScope($this->appContext(), 'user', 'ws-1', 'pro');
        $wrongOwner = $this->repo->findByKeyInScope($this->appContext(), 'user', 'ws-2', 'pro');

        self::assertIsArray($platform);
        self::assertSame('planv2pro001', $platform['uuid']);
        self::assertIsArray($workspaceOwned);
        self::assertSame('plan_ws1_pro', $workspaceOwned['uuid']);
        self::assertNull($wrongOwner);
    }

    public function testFindResolvableByKeyInScopeIncludesActiveAndArchivedButNotDraft(): void
    {
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_active01',
            'plan_key' => 'active-plan',
            'status' => 'active',
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-1',
        ]));
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_archiv01',
            'plan_key' => 'archived-plan',
            'status' => 'archived',
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-1',
        ]));
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_draft001',
            'plan_key' => 'draft-plan',
            'status' => 'draft',
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-1',
        ]));

        self::assertNotNull(
            $this->repo->findResolvableByKeyInScope($this->appContext(), 'user', 'ws-1', 'active-plan')
        );
        self::assertNotNull(
            $this->repo->findResolvableByKeyInScope($this->appContext(), 'user', 'ws-1', 'archived-plan')
        );
        self::assertNull(
            $this->repo->findResolvableByKeyInScope($this->appContext(), 'user', 'ws-1', 'draft-plan')
        );
        self::assertNotNull($this->repo->findByKeyInScope($this->appContext(), 'user', 'ws-1', 'draft-plan'));
    }

    public function testListInScopeOrdersBySortOrderThenPlanKeyAndExcludesOtherScopes(): void
    {
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_ws1_t01',
            'plan_key' => 'team',
            'sort_order' => 20,
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-1',
        ]));
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_ws1_b01',
            'plan_key' => 'basic',
            'sort_order' => 10,
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-1',
        ]));
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_ws2_a01',
            'plan_key' => 'alpha',
            'sort_order' => 5,
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-2',
        ]));

        self::assertSame(
            ['basic', 'team'],
            array_column($this->repo->listInScope($this->appContext(), 'user', 'ws-1'), 'plan_key')
        );

        // Platform scope (tenant, '') still shows the two harness-seeded plans, unaffected.
        self::assertSame(
            ['free', 'pro'],
            array_column($this->repo->listInScope($this->appContext(), 'tenant', ''), 'plan_key')
        );
    }

    public function testMaxUpdatedAtInScopeIsScopedIndependently(): void
    {
        self::assertNull($this->repo->maxUpdatedAtInScope($this->appContext(), 'user', 'ws-1'));

        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_ws1_m01',
            'plan_key' => 'basic',
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-1',
            'updated_at' => '2026-06-10 10:00:00',
        ]));
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_ws1_m02',
            'plan_key' => 'pro',
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-1',
            'updated_at' => '2026-06-10 12:00:00',
        ]));
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_ws2_m01',
            'plan_key' => 'basic',
            'audience' => 'user',
            'owner_tenant_uuid' => 'ws-2',
            'updated_at' => '2026-06-15 00:00:00',
        ]));

        self::assertSame(
            '2026-06-10 12:00:00',
            $this->repo->maxUpdatedAtInScope($this->appContext(), 'user', 'ws-1')
        );
        self::assertSame(
            '2026-06-15 00:00:00',
            $this->repo->maxUpdatedAtInScope($this->appContext(), 'user', 'ws-2')
        );
    }
}
