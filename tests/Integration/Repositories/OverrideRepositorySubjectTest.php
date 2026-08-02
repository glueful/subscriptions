<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\Tests\Support\V2SubscriptionsTestCase;
use Glueful\Helpers\Utils;

/**
 * Task 6: activeForSubject() added alongside the byte-compatible
 * activeForTenant() (Task 9 switches it to a Subject::tenant delegate).
 */
final class OverrideRepositorySubjectTest extends V2SubscriptionsTestCase
{
    private OverrideRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new OverrideRepository();
    }

    /** @param array<string,mixed> $overrides */
    private function insertOverride(array $overrides = []): void
    {
        $this->connection()->table('subscription_overrides')->insert(array_merge([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'tenant',
            'subject_uuid' => 'tenantA',
            'entitlement' => 'projects.limit',
            'value' => json_encode(999, JSON_THROW_ON_ERROR),
            'expires_at' => null,
        ], $overrides));
    }

    public function testActiveForSubjectMatchesTheExactTripleAndExcludesExpired(): void
    {
        $this->insertOverride(['entitlement' => 'projects.limit', 'value' => json_encode(999, JSON_THROW_ON_ERROR)]);
        $this->insertOverride([
            'entitlement' => 'reports.export',
            'value' => json_encode(true, JSON_THROW_ON_ERROR),
            'expires_at' => '2020-01-01 00:00:00',
        ]);

        $overrides = $this->repo->activeForSubject($this->appContext(), Subject::tenant('tenantA'));

        self::assertSame(['projects.limit' => 999], $overrides);
        self::assertArrayNotHasKey('reports.export', $overrides);
    }

    public function testActiveForSubjectDoesNotLeakAUserOverrideIntoTheTenantSubject(): void
    {
        $this->insertOverride([
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
            'entitlement' => 'projects.limit',
            'value' => json_encode(50, JSON_THROW_ON_ERROR),
        ]);

        $tenantOverrides = $this->repo->activeForSubject($this->appContext(), Subject::tenant('tenantA'));
        $userOverrides = $this->repo->activeForSubject($this->appContext(), Subject::user('tenantA', 'user-1'));

        self::assertSame([], $tenantOverrides);
        self::assertSame(['projects.limit' => 50], $userOverrides);
    }

    public function testActiveForSubjectIsTenantScoped(): void
    {
        $this->insertOverride([
            'tenant_uuid' => 'tenantB',
            'subject_uuid' => 'tenantB',
            'entitlement' => 'projects.limit',
            'value' => json_encode(1, JSON_THROW_ON_ERROR),
        ]);

        $overrides = $this->repo->activeForSubject($this->appContext(), Subject::tenant('tenantA'));

        self::assertSame([], $overrides);
    }
}
