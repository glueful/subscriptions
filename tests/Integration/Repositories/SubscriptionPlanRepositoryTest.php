<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

final class SubscriptionPlanRepositoryTest extends SubscriptionsTestCase
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
            'plan_key' => 'pro',
            'display_name' => 'Pro',
            'description' => 'Professional plan',
            'entitlements' => ['projects.limit' => 10, 'reports.export' => true],
            'payvia_priced_plan_uuid' => null,
            'status' => 'active',
            'sort_order' => 10,
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
        ], $overrides);
    }

    public function testInsertAndFindByKeyRoundTripsEntitlements(): void
    {
        $this->repo->insert($this->appContext(), $this->plan());

        $row = $this->repo->findByKey($this->appContext(), 'pro');

        self::assertIsArray($row);
        self::assertSame('pro', $row['plan_key']);
        self::assertSame(['projects.limit' => 10, 'reports.export' => true], $row['entitlements']);
    }

    public function testFindResolvableByKeyIncludesActiveAndArchivedButNotDraft(): void
    {
        $this->repo->insert($this->appContext(), $this->plan([
            'plan_key' => 'active-plan',
            'status' => 'active',
        ]));
        $this->repo->insert($this->appContext(), $this->plan([
            'plan_key' => 'archived-plan',
            'status' => 'archived',
        ]));
        $this->repo->insert($this->appContext(), $this->plan([
            'plan_key' => 'draft-plan',
            'status' => 'draft',
        ]));

        self::assertNotNull($this->repo->findResolvableByKey($this->appContext(), 'active-plan'));
        self::assertNotNull($this->repo->findResolvableByKey($this->appContext(), 'archived-plan'));
        self::assertNull($this->repo->findResolvableByKey($this->appContext(), 'draft-plan'));
        self::assertNotNull($this->repo->findByKey($this->appContext(), 'draft-plan'));
    }

    public function testListOrdersBySortOrderThenPlanKey(): void
    {
        $this->repo->insert($this->appContext(), $this->plan(['plan_key' => 'team', 'sort_order' => 20]));
        $this->repo->insert($this->appContext(), $this->plan(['plan_key' => 'basic', 'sort_order' => 10]));
        $this->repo->insert($this->appContext(), $this->plan(['plan_key' => 'alpha', 'sort_order' => 10]));

        self::assertSame(
            ['alpha', 'basic', 'team'],
            array_column($this->repo->list($this->appContext()), 'plan_key')
        );
    }

    public function testMaxUpdatedAtReturnsNullWhenEmptyAndLatestTimestampWhenPopulated(): void
    {
        self::assertNull($this->repo->maxUpdatedAt($this->appContext()));

        $this->repo->insert($this->appContext(), $this->plan([
            'plan_key' => 'basic',
            'updated_at' => '2026-06-10 10:00:00',
        ]));
        $this->repo->insert($this->appContext(), $this->plan([
            'plan_key' => 'pro',
            'updated_at' => '2026-06-10 12:00:00',
        ]));

        self::assertSame('2026-06-10 12:00:00', $this->repo->maxUpdatedAt($this->appContext()));
    }

    public function testDuplicatePlanKeyFailsAtDatabaseLevel(): void
    {
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_uuid_01',
            'plan_key' => 'pro',
        ]));

        $this->expectException(\Throwable::class);
        $this->repo->insert($this->appContext(), $this->plan([
            'uuid' => 'plan_uuid_02',
            'plan_key' => 'pro',
        ]));
    }

    public function testExistsChecksPlanKeyPresence(): void
    {
        $this->repo->insert($this->appContext(), $this->plan(['plan_key' => 'pro']));

        self::assertTrue($this->repo->exists($this->appContext(), 'pro'));
        self::assertFalse($this->repo->exists($this->appContext(), 'missing'));
    }
}
