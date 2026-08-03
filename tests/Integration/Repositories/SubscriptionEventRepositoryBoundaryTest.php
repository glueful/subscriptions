<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;

/**
 * Task 6: repository-boundary validation on SubscriptionEventRepository::insertOrThrow().
 * Coherence only (host existence remains SubjectResolverInterface's job): tenant_uuid,
 * subject_type, and subject_uuid must be non-empty; subject_type must be exactly
 * tenant|user; a tenant subject requires subject_uuid === tenant_uuid. Malformed events
 * throw InvalidArgumentException and never reach subscription_events.
 *
 * The Task-6 legacy-compat derivation is RETIRED as of the 2.0 activation: every
 * caller (SubscriptionService, SubscriptionEventProjector, and the fixtures) now
 * passes the subject triple explicitly, so a tenant-only event is a bug rather than
 * a legacy shape and is rejected here instead of being silently repaired.
 */
final class SubscriptionEventRepositoryBoundaryTest extends SubscriptionsTestCase
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
            'subject_type' => 'tenant',
            'subject_uuid' => 'tenantA',
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

    public function testEventWithNoSubjectColumnsAtAllIsRejectedAndNeverReachesSql(): void
    {
        // FLIPPED at the 2.0 activation: this shape used to be derived into a
        // coherent tenant self-subject. Deriving it now would mask a caller that
        // simply forgot the subject -- and for a user-subject caller it would
        // silently file the event under the WRONG subject.
        $event = $this->event();
        unset($event['subject_type'], $event['subject_uuid']);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->repo->insertOrThrow($this->appContext(), $event);
        } finally {
            self::assertSame(0, $this->eventCount());
        }
    }

    public function testExplicitCoherentTenantSubjectIsPersistedVerbatim(): void
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

    /**
     * A half-specified user subject stays rejected: an explicit
     * subject_type='user' whose subject_uuid is nulled out must never fall back to
     * the tenant's own uuid, which would manufacture a coherent-looking but wrong
     * "user" identity (the tenant/uuid-match check only fires for subject_type=tenant).
     */
    public function testPartiallySpecifiedUserSubjectWithMissingUuidThrowsAndNeverReachesSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->repo->insertOrThrow($this->appContext(), $this->event([
                'subject_type' => 'user',
                'subject_uuid' => null,
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
