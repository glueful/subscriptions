<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Lifecycle;

use Glueful\Extensions\Subscriptions\Lifecycle\SubscriptionSubjectDataPurger;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\Tests\Support\RecordingTenantContextRunner;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

/**
 * Task 12 -- SubscriptionSubjectDataPurger (spec §9).
 *
 * User form: removes exactly that subject's own subscription/overrides/events plus
 * any receipt whose RESOLVED OR CANDIDATE triple names it -- never plans, never a
 * sibling user, never the workspace's own tenant-subject rows.
 *
 * Tenant form: removes EVERY subscription/override/event/receipt (resolved-or-
 * candidate) whose tenant matches -- the workspace's own subject AND every member's
 * -- then only that workspace's `audience='user'` plans; platform plans and foreign
 * workspaces are untouched.
 *
 * Both forms are idempotent (a second call finds/deletes nothing) and run through
 * `TenantIntegration::runAsSystemOr()` (proven with a recording runner).
 */
final class PurgerTest extends SubscriptionsTestCase
{
    private function purger(): SubscriptionSubjectDataPurger
    {
        return new SubscriptionSubjectDataPurger($this->appContext());
    }

    private function seedOverride(
        string $tenantUuid,
        string $subjectType,
        string $subjectUuid,
        string $entitlement = 'flag.x'
    ): void {
        $this->connection()->table('subscription_overrides')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'entitlement' => $entitlement,
            'value' => json_encode(true, JSON_THROW_ON_ERROR),
        ]);
    }

    private function seedEvent(string $tenantUuid, string $subjectType, string $subjectUuid): void
    {
        (new SubscriptionEventRepository())->insertOrThrow($this->appContext(), [
            'tenant_uuid' => $tenantUuid,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'type' => 'created',
            'source' => 'manual',
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function seedReceipt(array $overrides): void
    {
        $this->connection()->table('subscription_provider_event_receipts')->insert(array_merge([
            'uuid' => Utils::generateNanoID(12),
            'provider_gateway' => 'stripe',
            'provider_logical_event_key' => Utils::generateNanoID(12),
            'event_type' => 'subscription.created',
            'outcome' => 'accepted',
        ], $overrides));
    }

    private function seedPlan(string $planKey, string $audience, string $owner): void
    {
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => Utils::generateNanoID(12),
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'entitlements' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'sort_order' => 0,
            'audience' => $audience,
            'owner_tenant_uuid' => $owner,
        ]);
    }

    /**
     * The full fixture every test in this file shares: a workspace ('tenantA') with
     * its own subscription, two members (user-1, user-2), a member plan; a totally
     * unrelated foreign workspace ('tenantB') with its own subscription, member, and
     * member plan; and the always-seeded platform plans (free/pro).
     */
    private function seedFixture(): void
    {
        // tenantA: workspace self-subject + two members.
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free']);
        $this->seedOverride('tenantA', 'tenant', 'tenantA');
        $this->seedEvent('tenantA', 'tenant', 'tenantA');

        $this->seedPlan('member', 'user', 'tenantA');
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
            'plan_key' => 'member',
        ]);
        $this->seedOverride('tenantA', 'user', 'user-1');
        $this->seedEvent('tenantA', 'user', 'user-1');
        $this->seedReceipt([
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
        ]);
        // A rejected candidate-only receipt naming user-1 (never resolved).
        $this->seedReceipt([
            'outcome' => 'rejected',
            'rejection_code' => 'invalid_subject',
            'candidate_tenant_uuid' => 'tenantA',
            'candidate_subject_type' => 'user',
            'candidate_subject_uuid' => 'user-1',
        ]);

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'user',
            'subject_uuid' => 'user-2',
            'plan_key' => 'member',
        ]);
        $this->seedOverride('tenantA', 'user', 'user-2');
        $this->seedEvent('tenantA', 'user', 'user-2');
        $this->seedReceipt([
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'user',
            'subject_uuid' => 'user-2',
        ]);

        // tenantB: a completely foreign workspace that must survive every tenantA
        // purge untouched.
        $this->seedSubscription(['tenant_uuid' => 'tenantB', 'plan_key' => 'free']);
        $this->seedOverride('tenantB', 'tenant', 'tenantB');
        $this->seedEvent('tenantB', 'tenant', 'tenantB');
        $this->seedPlan('member', 'user', 'tenantB');
        $this->seedSubscription([
            'tenant_uuid' => 'tenantB',
            'subject_type' => 'user',
            'subject_uuid' => 'user-3',
            'plan_key' => 'member',
        ]);
        $this->seedOverride('tenantB', 'user', 'user-3');
        $this->seedEvent('tenantB', 'user', 'user-3');
        $this->seedReceipt([
            'tenant_uuid' => 'tenantB',
            'subject_type' => 'user',
            'subject_uuid' => 'user-3',
        ]);
    }

    private function subscriptionExists(string $tenantUuid, string $subjectType, string $subjectUuid): bool
    {
        return $this->connection()->table('subscriptions')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->where('subject_type', '=', $subjectType)
            ->where('subject_uuid', '=', $subjectUuid)
            ->first() !== null;
    }

    private function overrideCount(string $tenantUuid, string $subjectType, string $subjectUuid): int
    {
        return count($this->connection()->table('subscription_overrides')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->where('subject_type', '=', $subjectType)
            ->where('subject_uuid', '=', $subjectUuid)
            ->get());
    }

    private function eventCount(string $tenantUuid, string $subjectType, string $subjectUuid): int
    {
        return count($this->connection()->table('subscription_events')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->where('subject_type', '=', $subjectType)
            ->where('subject_uuid', '=', $subjectUuid)
            ->get());
    }

    private function planExists(string $planKey, string $audience, string $owner): bool
    {
        return $this->connection()->table('subscription_plans')
            ->where('plan_key', '=', $planKey)
            ->where('audience', '=', $audience)
            ->where('owner_tenant_uuid', '=', $owner)
            ->first() !== null;
    }

    // ---------------------------------------------------------------
    // User form
    // ---------------------------------------------------------------

    public function testPurgeUserSubjectRemovesOnlyThatUsersSubscriptionOverridesEventsAndReceipts(): void
    {
        $this->seedFixture();

        $this->purger()->purgeSubject(Subject::user('tenantA', 'user-1'));

        self::assertFalse($this->subscriptionExists('tenantA', 'user', 'user-1'));
        self::assertSame(0, $this->overrideCount('tenantA', 'user', 'user-1'));
        self::assertSame(0, $this->eventCount('tenantA', 'user', 'user-1'));
        self::assertSame(
            0,
            $this->connection()->table('subscription_provider_event_receipts')
                ->where('subject_uuid', '=', 'user-1')
                ->count()
        );
        self::assertSame(
            0,
            $this->connection()->table('subscription_provider_event_receipts')
                ->where('candidate_subject_uuid', '=', 'user-1')
                ->count()
        );
    }

    public function testPurgeUserSubjectNeverTouchesASiblingUser(): void
    {
        $this->seedFixture();

        $this->purger()->purgeSubject(Subject::user('tenantA', 'user-1'));

        self::assertTrue($this->subscriptionExists('tenantA', 'user', 'user-2'));
        self::assertSame(1, $this->overrideCount('tenantA', 'user', 'user-2'));
        self::assertSame(1, $this->eventCount('tenantA', 'user', 'user-2'));
        self::assertSame(
            1,
            $this->connection()->table('subscription_provider_event_receipts')
                ->where('subject_uuid', '=', 'user-2')
                ->count()
        );
    }

    public function testPurgeUserSubjectNeverTouchesTheWorkspacesOwnSubject(): void
    {
        $this->seedFixture();

        $this->purger()->purgeSubject(Subject::user('tenantA', 'user-1'));

        self::assertTrue($this->subscriptionExists('tenantA', 'tenant', 'tenantA'));
        self::assertSame(1, $this->overrideCount('tenantA', 'tenant', 'tenantA'));
        self::assertSame(1, $this->eventCount('tenantA', 'tenant', 'tenantA'));
    }

    public function testPurgeUserSubjectNeverTouchesAnyPlan(): void
    {
        $this->seedFixture();

        $this->purger()->purgeSubject(Subject::user('tenantA', 'user-1'));

        self::assertTrue($this->planExists('member', 'user', 'tenantA'));
        self::assertTrue($this->planExists('free', 'tenant', ''));
        self::assertTrue($this->planExists('pro', 'tenant', ''));
    }

    public function testPurgeUserSubjectIsIdempotent(): void
    {
        $this->seedFixture();
        $purger = $this->purger();

        $first = $purger->purgeSubject(Subject::user('tenantA', 'user-1'));
        self::assertGreaterThan(0, array_sum($first));

        $second = $purger->purgeSubject(Subject::user('tenantA', 'user-1'));

        foreach ($second as $table => $count) {
            self::assertSame(0, $count, "Expected zero rows deleted from {$table} on the second purge.");
        }
    }

    // ---------------------------------------------------------------
    // Tenant form
    // ---------------------------------------------------------------

    public function testPurgeTenantSubjectRemovesTheWorkspacesOwnSubjectAndEveryMember(): void
    {
        $this->seedFixture();

        $this->purger()->purgeSubject(Subject::tenant('tenantA'));

        self::assertFalse($this->subscriptionExists('tenantA', 'tenant', 'tenantA'));
        self::assertFalse($this->subscriptionExists('tenantA', 'user', 'user-1'));
        self::assertFalse($this->subscriptionExists('tenantA', 'user', 'user-2'));
        self::assertSame(0, $this->connection()->table('subscription_overrides')
            ->where('tenant_uuid', '=', 'tenantA')->count());
        self::assertSame(0, $this->connection()->table('subscription_events')
            ->where('tenant_uuid', '=', 'tenantA')->count());
        self::assertSame(0, $this->connection()->table('subscription_provider_event_receipts')
            ->where('tenant_uuid', '=', 'tenantA')->count());
        self::assertSame(0, $this->connection()->table('subscription_provider_event_receipts')
            ->where('candidate_tenant_uuid', '=', 'tenantA')->count());
    }

    public function testPurgeTenantSubjectRemovesOnlyItsOwnMemberPlan(): void
    {
        $this->seedFixture();

        $this->purger()->purgeSubject(Subject::tenant('tenantA'));

        self::assertFalse($this->planExists('member', 'user', 'tenantA'));
    }

    public function testPurgeTenantSubjectNeverTouchesPlatformPlans(): void
    {
        $this->seedFixture();

        $this->purger()->purgeSubject(Subject::tenant('tenantA'));

        self::assertTrue($this->planExists('free', 'tenant', ''));
        self::assertTrue($this->planExists('pro', 'tenant', ''));
    }

    public function testPurgeTenantSubjectNeverTouchesAForeignWorkspace(): void
    {
        $this->seedFixture();

        $this->purger()->purgeSubject(Subject::tenant('tenantA'));

        self::assertTrue($this->subscriptionExists('tenantB', 'tenant', 'tenantB'));
        self::assertTrue($this->subscriptionExists('tenantB', 'user', 'user-3'));
        self::assertSame(1, $this->overrideCount('tenantB', 'tenant', 'tenantB'));
        self::assertSame(1, $this->overrideCount('tenantB', 'user', 'user-3'));
        self::assertSame(1, $this->eventCount('tenantB', 'tenant', 'tenantB'));
        self::assertSame(1, $this->eventCount('tenantB', 'user', 'user-3'));
        self::assertSame(
            1,
            $this->connection()->table('subscription_provider_event_receipts')
                ->where('tenant_uuid', '=', 'tenantB')->count()
        );
        self::assertTrue($this->planExists('member', 'user', 'tenantB'));
    }

    public function testPurgeTenantSubjectIsIdempotent(): void
    {
        $this->seedFixture();
        $purger = $this->purger();

        $first = $purger->purgeSubject(Subject::tenant('tenantA'));
        self::assertGreaterThan(0, array_sum($first));

        $second = $purger->purgeSubject(Subject::tenant('tenantA'));

        foreach ($second as $table => $count) {
            self::assertSame(0, $count, "Expected zero rows deleted from {$table} on the second purge.");
        }
    }

    // ---------------------------------------------------------------
    // Context-runner discipline (spec §9)
    // ---------------------------------------------------------------

    public function testPurgeSubjectRunsThroughRunAsSystem(): void
    {
        $this->seedFixture();
        $runner = new RecordingTenantContextRunner();
        $this->bind(\Glueful\Extensions\Contracts\Tenancy\TenantContextRunner::class, $runner);

        $this->purger()->purgeSubject(Subject::user('tenantA', 'user-1'));

        self::assertNotSame([], $runner->calls());
        foreach ($runner->calls() as $call) {
            self::assertSame('system', $call['mode']);
        }
    }

    public function testPurgeSubjectWorksUnchangedWhenNoRunnerIsBound(): void
    {
        $this->seedFixture();

        // No TenantContextRunner bound at all -- the contracts-absent/no-binding path.
        $counts = $this->purger()->purgeSubject(Subject::user('tenantA', 'user-1'));

        self::assertGreaterThan(0, array_sum($counts));
        self::assertFalse($this->subscriptionExists('tenantA', 'user', 'user-1'));
    }

    // ---------------------------------------------------------------
    // Safety guard: an empty tenant_uuid/subject_uuid is never a real subject
    // ---------------------------------------------------------------

    public function testPurgeSubjectRefusesAnEmptyTenantSubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->purger()->purgeSubject(Subject::tenant(''));
    }

    public function testPurgeSubjectRefusesAUserSubjectWithAnEmptyTenantUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->purger()->purgeSubject(Subject::user('', 'user-1'));
    }

    public function testPurgeSubjectRefusesAUserSubjectWithAnEmptyUserUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->purger()->purgeSubject(Subject::user('tenantA', ''));
    }
}
