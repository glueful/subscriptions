<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\V2SubscriptionsTestCase;

/**
 * Task 6: repository-boundary validation on SubscriptionEventRepository::insertOrThrow().
 * Coherence only (host existence remains SubjectResolverInterface's job): tenant_uuid,
 * subject_type, and subject_uuid must be non-empty; subject_type must be exactly
 * tenant|user; a tenant subject requires subject_uuid === tenant_uuid. Malformed events
 * throw InvalidArgumentException and never reach subscription_events.
 *
 * Legacy-compat decision (documented in the task-6 report): the 1.x call sites
 * (SubscriptionService, SubscriptionEventProjector, and the pre-v2 event fixtures) insert
 * events carrying only tenant_uuid, with no subject_type/subject_uuid key at all -- Task 9
 * is the coordinated cutover that teaches those callers to pass an explicit Subject. Until
 * then, insertOrThrow() derives a coherent tenant self-subject (subject_type='tenant',
 * subject_uuid=tenant_uuid) whenever the caller omitted -- or passed empty -- those two
 * columns, BEFORE running the coherence check below. This keeps every legacy call site
 * satisfying the new validation without changing in this task.
 */
final class SubscriptionEventRepositoryBoundaryTest extends V2SubscriptionsTestCase
{
    private SubscriptionEventRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SubscriptionEventRepository();
    }

    /** @param array<string,mixed> $overrides */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'tenant_uuid' => 'tenantA',
            'type' => 'subscription.past_due',
            'source' => 'provider_event',
            'provider_gateway' => 'paystack',
            'provider_logical_event_key' => 'k1',
        ], $overrides);
    }

    private function eventCount(): int
    {
        return count($this->connection()->table('subscription_events')->get());
    }

    public function testLegacyShapedEventWithNoSubjectColumnsDerivesACoherentTenantSelfSubject(): void
    {
        $this->repo->insertOrThrow($this->appContext(), $this->event());

        $row = $this->connection()->table('subscription_events')->first();
        self::assertIsArray($row);
        self::assertSame('tenant', $row['subject_type']);
        self::assertSame('tenantA', $row['subject_uuid']);
    }

    public function testExplicitCoherentUserSubjectIsPersistedVerbatim(): void
    {
        $this->repo->insertOrThrow($this->appContext(), $this->event([
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
        ]));

        $row = $this->connection()->table('subscription_events')->first();
        self::assertIsArray($row);
        self::assertSame('user', $row['subject_type']);
        self::assertSame('user-1', $row['subject_uuid']);
    }

    public function testEmptyTenantUuidThrowsAndNeverReachesSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->repo->insertOrThrow($this->appContext(), $this->event(['tenant_uuid' => '']));
        } finally {
            self::assertSame(0, $this->eventCount());
        }
    }

    public function testSubjectTypeOutsideTenantOrUserThrowsAndNeverReachesSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->repo->insertOrThrow($this->appContext(), $this->event([
                'subject_type' => 'admin',
                'subject_uuid' => 'whatever',
            ]));
        } finally {
            self::assertSame(0, $this->eventCount());
        }
    }

    public function testExplicitEmptySubjectUuidThrowsAndNeverReachesSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->repo->insertOrThrow($this->appContext(), $this->event([
                'subject_type' => 'user',
                'subject_uuid' => '',
            ]));
        } finally {
            self::assertSame(0, $this->eventCount());
        }
    }

    public function testTenantSubjectWithMismatchedSubjectUuidThrowsAndNeverReachesSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->repo->insertOrThrow($this->appContext(), $this->event([
                'subject_type' => 'tenant',
                'subject_uuid' => 'some-other-uuid',
            ]));
        } finally {
            self::assertSame(0, $this->eventCount());
        }
    }

    public function testAppendPropagatesTheValidationErrorRatherThanSwallowingIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->repo->append($this->appContext(), $this->event(['tenant_uuid' => '']));
        } finally {
            self::assertSame(0, $this->eventCount());
        }
    }
}
