<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Subject-aware finder/updater added alongside the byte-compatible
 * findByTenant()/updateByTenant(), which are now Subject::tenant delegates
 * of these since the coordinated activation. Runs on the shared 2.0 harness
 * (006 applied) so subject_type/subject_uuid are real columns.
 */
final class SubscriptionRepositorySubjectTest extends SubscriptionsTestCase
{
    private SubscriptionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SubscriptionRepository();
    }

    public function testFindBySubjectMatchesTheExactTriple(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro']);

        $row = $this->repo->findBySubject($this->appContext(), Subject::tenant('tenantA'));

        self::assertIsArray($row);
        self::assertSame('tenantA', $row['tenant_uuid']);
        self::assertSame('tenant', $row['subject_type']);
        self::assertSame('tenantA', $row['subject_uuid']);
        self::assertSame('pro', $row['plan_key']);
    }

    public function testFindBySubjectDoesNotReturnAUserRowForATenantSubject(): void
    {
        // A membership plan owned by tenantA, and a user subscription against it.
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => 'planv2wsmemx',
            'plan_key' => 'member',
            'display_name' => 'Member',
            'entitlements' => json_encode(['content.access' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => 'tenantA',
        ]);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
            'plan_key' => 'member',
        ]);

        // No tenant-self-subject row exists for tenantA -- only the user row above.
        $row = $this->repo->findBySubject($this->appContext(), Subject::tenant('tenantA'));

        self::assertNull($row, 'A user-subject row must never satisfy a Subject::tenant() triple match.');
    }

    public function testFindBySubjectDoesNotReturnATenantRowForADifferentUserSubject(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free']);

        $row = $this->repo->findBySubject($this->appContext(), Subject::user('tenantA', 'user-1'));

        self::assertNull($row);
    }

    public function testFindBySubjectIsTenantScoped(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free']);
        $this->seedSubscription(['tenant_uuid' => 'tenantB', 'plan_key' => 'pro']);

        $row = $this->repo->findBySubject($this->appContext(), Subject::tenant('tenantB'));

        self::assertIsArray($row);
        self::assertSame('tenantB', $row['tenant_uuid']);
        self::assertSame('pro', $row['plan_key']);
    }

    public function testUpdateBySubjectOnlyTouchesTheMatchingTriple(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free', 'status' => 'active']);
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => 'planv2wsmemy',
            'plan_key' => 'member',
            'display_name' => 'Member',
            'entitlements' => json_encode(['content.access' => true], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => 'tenantA',
        ]);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
            'plan_key' => 'member',
            'status' => 'active',
        ]);

        $this->repo->updateBySubject($this->appContext(), Subject::user('tenantA', 'user-1'), [
            'status' => 'canceled',
        ]);

        $tenantRow = $this->repo->findBySubject($this->appContext(), Subject::tenant('tenantA'));
        $userRow = $this->repo->findBySubject($this->appContext(), Subject::user('tenantA', 'user-1'));

        self::assertSame('active', $tenantRow['status']);
        self::assertSame('canceled', $userRow['status']);
        self::assertNotNull($userRow['updated_at']);
    }
}
