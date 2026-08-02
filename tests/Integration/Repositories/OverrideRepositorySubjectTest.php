<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

/**
 * activeForSubject() added alongside the byte-compatible activeForTenant(),
 * which is now a Subject::tenant delegate of it since the coordinated
 * activation.
 */
final class OverrideRepositorySubjectTest extends SubscriptionsTestCase
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

    // ===========================================
    // C2 -- the shipped, supported override writer
    // ===========================================
    //
    // 1.x shipped no writer for subscription_overrides, so hosts wrote the table
    // directly. Post-006 a 1.x-shaped insert leaves subject_uuid at its `''`
    // DEFAULT, which activeForSubject()'s triple match never returns -- a deny
    // override written that way silently GRANTS. upsertForSubject() is the
    // supported way to never hit that.

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return $this->connection()->table('subscription_overrides')->get();
    }

    public function testUpsertForSubjectCreatesThenUpdatesTheSameRow(): void
    {
        $subject = Subject::tenant('tenantA');

        $this->repo->upsertForSubject($this->appContext(), $subject, 'projects.limit', 10);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('tenantA', $rows[0]['tenant_uuid']);
        self::assertSame('tenant', $rows[0]['subject_type']);
        self::assertSame('tenantA', $rows[0]['subject_uuid']);
        self::assertSame('projects.limit', $rows[0]['entitlement']);
        self::assertSame('10', $rows[0]['value']); // json-encoded, like every existing row
        self::assertNotEmpty($rows[0]['uuid']);
        $firstUuid = $rows[0]['uuid'];

        $this->repo->upsertForSubject(
            $this->appContext(),
            $subject,
            'projects.limit',
            99,
            expiresAt: '2999-01-01 00:00:00',
            reason: 'comped'
        );

        $rows = $this->rows();
        self::assertCount(1, $rows, 'the subject-scoped unique row is UPDATED, never duplicated');
        self::assertSame($firstUuid, $rows[0]['uuid']);
        self::assertSame('99', $rows[0]['value']);
        self::assertSame('2999-01-01 00:00:00', $rows[0]['expires_at']);
        self::assertSame('comped', $rows[0]['reason']);
    }

    public function testUpsertForSubjectIsScopedToOneSubjectNotOneTenant(): void
    {
        $this->repo->upsertForSubject($this->appContext(), Subject::tenant('tenantA'), 'reports.export', false);
        $this->repo->upsertForSubject($this->appContext(), Subject::user('tenantA', 'user-1'), 'reports.export', true);

        self::assertCount(2, $this->rows());
        self::assertSame(
            ['reports.export' => false],
            $this->repo->activeForSubject($this->appContext(), Subject::tenant('tenantA'))
        );
        self::assertSame(
            ['reports.export' => true],
            $this->repo->activeForSubject($this->appContext(), Subject::user('tenantA', 'user-1'))
        );
    }

    public function testARowWrittenByUpsertForSubjectIsReturnedByActiveForSubject(): void
    {
        $this->repo->upsertForSubject($this->appContext(), Subject::tenant('tenantA'), 'projects.limit', 777);

        self::assertSame(
            ['projects.limit' => 777],
            $this->repo->activeForSubject($this->appContext(), Subject::tenant('tenantA'))
        );
        // ...and through the 1.x facade, which is a Subject::tenant delegate.
        self::assertSame(
            ['projects.limit' => 777],
            $this->repo->activeForTenant($this->appContext(), 'tenantA')
        );
    }

    public function testUpsertForSubjectRoundTripsNonScalarAndBooleanValues(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->repo->upsertForSubject($this->appContext(), $subject, 'reports.export', false);
        $this->repo->upsertForSubject($this->appContext(), $subject, 'features', ['a' => 1, 'b' => true]);

        self::assertEqualsCanonicalizing(
            ['reports.export' => false, 'features' => ['a' => 1, 'b' => true]],
            $this->repo->activeForSubject($this->appContext(), $subject)
        );
    }

    public function testUpsertForSubjectHonoursAnExpiry(): void
    {
        $this->repo->upsertForSubject(
            $this->appContext(),
            Subject::tenant('tenantA'),
            'projects.limit',
            1,
            expiresAt: '2020-01-01 00:00:00'
        );

        self::assertCount(1, $this->rows());
        self::assertSame([], $this->repo->activeForSubject($this->appContext(), Subject::tenant('tenantA')));
    }

    public function testDeleteForSubjectRemovesOnlyThatSubjectsRowAndIsANoOpWhenAbsent(): void
    {
        $this->repo->upsertForSubject($this->appContext(), Subject::tenant('tenantA'), 'projects.limit', 10);
        $this->repo->upsertForSubject($this->appContext(), Subject::user('tenantA', 'user-1'), 'projects.limit', 20);

        $this->repo->deleteForSubject($this->appContext(), Subject::tenant('tenantA'), 'projects.limit');

        self::assertSame([], $this->repo->activeForSubject($this->appContext(), Subject::tenant('tenantA')));
        self::assertSame(
            ['projects.limit' => 20],
            $this->repo->activeForSubject($this->appContext(), Subject::user('tenantA', 'user-1'))
        );

        // Re-runnable: deleting an absent override is a no-op, never an error.
        $this->repo->deleteForSubject($this->appContext(), Subject::tenant('tenantA'), 'projects.limit');
        self::assertCount(1, $this->rows());
    }

    /**
     * The regression this writer exists to prevent: the 1.x-shaped direct insert
     * (no subject_type/subject_uuid) is invisible to the subject-scoped read, so a
     * deny override written that way GRANTS. Documented as a breaking change; the
     * writer above is the supported alternative.
     */
    public function testALegacyShapedDirectInsertIsInvisibleWhereasTheWriterIsNot(): void
    {
        $this->connection()->table('subscription_overrides')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'tenantA',
            'entitlement' => 'reports.export',
            'value' => json_encode(false, JSON_THROW_ON_ERROR),
        ]);

        self::assertSame([], $this->repo->activeForSubject($this->appContext(), Subject::tenant('tenantA')));

        $this->repo->upsertForSubject($this->appContext(), Subject::tenant('tenantA'), 'reports.export', false);

        self::assertSame(
            ['reports.export' => false],
            $this->repo->activeForSubject($this->appContext(), Subject::tenant('tenantA'))
        );
    }
}
