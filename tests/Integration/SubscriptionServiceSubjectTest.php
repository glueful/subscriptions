<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubscriptionConflictException;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\CallablePuller;
use Glueful\Extensions\Subscriptions\Tests\Support\PermissiveSubjectResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;

/**
 * Task 9 -- the subject-aware SubscriptionService core (spec §7/§8): subject
 * validation, plan-audience matching, the plan_uuid + denormalized plan_key write
 * rule, one-transaction state+event atomicity, and the deterministic
 * lost-race outcomes.
 *
 * The 1.x tenant facade's own equivalence proof lives in
 * SubscriptionServiceFacadeTest (spec §11.2).
 */
final class SubscriptionServiceSubjectTest extends SubscriptionsTestCase
{
    private const PLATFORM_FREE = 'planv2free01';
    private const PLATFORM_PRO = 'planv2pro001';

    private function service(
        ?SubjectResolverInterface $subjects = null,
        ?SubscriptionEventRepository $events = null,
        ?callable $puller = null,
    ): SubscriptionService {
        return new SubscriptionService(
            new SubscriptionRepository(),
            $events ?? new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            $subjects ?? new DefaultSubjectResolver(),
            $puller !== null ? new CallablePuller($puller) : null,
        );
    }

    private function memberService(): SubscriptionService
    {
        return $this->service(new PermissiveSubjectResolver());
    }

    /** @return list<array<string,mixed>> */
    private function events(Subject $subject): array
    {
        return $this->connection()->table('subscription_events')
            ->where('tenant_uuid', '=', $subject->tenantUuid)
            ->where('subject_type', '=', $subject->type)
            ->where('subject_uuid', '=', $subject->uuid)
            ->get();
    }

    /** @param array<string,mixed> $overrides */
    private function seedPlan(array $overrides): string
    {
        $uuid = (string) ($overrides['uuid'] ?? Utils::generateNanoID(12));

        $this->connection()->table('subscription_plans')->insert(array_merge([
            'uuid' => $uuid,
            'plan_key' => 'member-pro',
            'display_name' => 'Member Pro',
            'entitlements' => json_encode(['posts.premium' => true], JSON_THROW_ON_ERROR),
            'provider_price_id' => null,
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => 'tenantA',
        ], $overrides));

        return $uuid;
    }

    // ---------------------------------------------------------------
    // Subject validation (spec §4)
    // ---------------------------------------------------------------

    public function testEveryCoreMethodRejectsASubjectTheResolverRefuses(): void
    {
        $service = $this->service(); // DefaultSubjectResolver rejects ALL user subjects
        $subject = Subject::user('tenantA', 'user-1');

        foreach (
            [
                fn (): mixed => $service->currentFor($subject),
                fn (): mixed => $service->startFor($subject, self::PLATFORM_FREE),
                fn (): mixed => $service->changePlanFor($subject, self::PLATFORM_FREE),
                fn (): mixed => $service->cancelFor($subject),
                fn (): mixed => $service->reconcileFor($subject),
            ] as $index => $call
        ) {
            try {
                $call();
                self::fail("Call #{$index} accepted a subject the resolver rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('invalid subject', $e->getMessage());
            }
        }

        self::assertSame(0, $this->connection()->table('subscriptions')->count());
    }

    public function testIncoherentTenantSubjectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid subject');

        $this->service()->startFor(new Subject('tenantA', 'tenant', 'someone-else'), self::PLATFORM_FREE);
    }

    // ---------------------------------------------------------------
    // Plan-audience matching (spec §4)
    // ---------------------------------------------------------------

    public function testUserSubjectCannotStartAPlatformPlan(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->memberService()->startFor(Subject::user('tenantA', 'user-1'), self::PLATFORM_PRO);
    }

    public function testTenantSubjectCannotStartAMemberPlan(): void
    {
        $memberPlan = $this->seedPlan(['uuid' => 'planmember01']);

        $this->expectException(\InvalidArgumentException::class);

        $this->memberService()->startFor(Subject::tenant('tenantA'), $memberPlan);
    }

    public function testUserSubjectCannotStartAnotherWorkspacesMemberPlan(): void
    {
        $foreign = $this->seedPlan(['uuid' => 'planforeign1', 'owner_tenant_uuid' => 'tenantB']);

        $this->expectException(\InvalidArgumentException::class);

        $this->memberService()->startFor(Subject::user('tenantA', 'user-1'), $foreign);
    }

    public function testUserSubjectStartsItsOwnWorkspacesMemberPlan(): void
    {
        $memberPlan = $this->seedPlan(['uuid' => 'planmember01', 'provider_price_id' => 'price_member']);
        $subject = Subject::user('tenantA', 'user-1');

        $row = $this->memberService()->startFor($subject, $memberPlan);

        self::assertSame('tenantA', $row['tenant_uuid']);
        self::assertSame('user', $row['subject_type']);
        self::assertSame('user-1', $row['subject_uuid']);
        self::assertSame($memberPlan, $row['plan_uuid']);
        self::assertSame('member-pro', $row['plan_key']);
        self::assertSame('price_member', $row['provider_price_id']);
    }

    public function testMembershipAndWorkspaceSubscriptionCoexistInTheSameTenant(): void
    {
        $memberPlan = $this->seedPlan(['uuid' => 'planmember01']);
        $service = $this->memberService();

        $service->startFor(Subject::tenant('tenantA'), self::PLATFORM_PRO);
        $service->startFor(Subject::user('tenantA', 'user-1'), $memberPlan);

        self::assertSame(2, $this->connection()->table('subscriptions')->count());
        self::assertSame(
            self::PLATFORM_PRO,
            $service->currentFor(Subject::tenant('tenantA'))['plan_uuid']
        );
        self::assertSame(
            $memberPlan,
            $service->currentFor(Subject::user('tenantA', 'user-1'))['plan_uuid']
        );
    }

    public function testNonAssignablePlanUuidIsRejected(): void
    {
        $draft = $this->seedPlan([
            'uuid' => 'plandraft001',
            'plan_key' => 'member-draft',
            'status' => 'draft',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->memberService()->startFor(Subject::user('tenantA', 'user-1'), $draft);
    }

    public function testUnknownPlanUuidIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->startFor(Subject::tenant('tenantA'), 'nosuchplan01');
    }

    // ---------------------------------------------------------------
    // Plan reference + event subject columns
    // ---------------------------------------------------------------

    public function testStartForWritesBothPlanReferencesAndTheEventSubjectTriple(): void
    {
        $subject = Subject::tenant('tenantA');

        $row = $this->service()->startFor($subject, self::PLATFORM_PRO);

        self::assertSame(self::PLATFORM_PRO, $row['plan_uuid']);
        self::assertSame('pro', $row['plan_key']);
        self::assertSame('tenant', $row['subject_type']);
        self::assertSame('tenantA', $row['subject_uuid']);

        $events = $this->events($subject);
        self::assertCount(1, $events);
        self::assertSame('created', $events[0]['type']);
        self::assertSame('tenantA', $events[0]['tenant_uuid']);
        self::assertSame('tenant', $events[0]['subject_type']);
        self::assertSame('tenantA', $events[0]['subject_uuid']);
    }

    public function testChangePlanForRefreshesUuidKeyAndProviderPrice(): void
    {
        $this->connection()->table('subscription_plans')
            ->where('uuid', '=', self::PLATFORM_PRO)
            ->update(['provider_price_id' => 'price_pro']);

        $subject = Subject::tenant('tenantA');
        $service = $this->service();
        $service->startFor($subject, self::PLATFORM_FREE);

        $row = $service->changePlanFor($subject, self::PLATFORM_PRO);

        self::assertSame(self::PLATFORM_PRO, $row['plan_uuid']);
        self::assertSame('pro', $row['plan_key']);
        self::assertSame('price_pro', $row['provider_price_id']);

        $events = $this->events($subject);
        self::assertCount(2, $events);
        self::assertSame('plan_changed', $events[1]['type']);
        self::assertSame('tenant', $events[1]['subject_type']);
    }

    public function testCancelForTouchesOnlyItsOwnSubject(): void
    {
        $memberPlan = $this->seedPlan(['uuid' => 'planmember01']);
        $service = $this->memberService();
        $tenant = Subject::tenant('tenantA');
        $member = Subject::user('tenantA', 'user-1');

        $service->startFor($tenant, self::PLATFORM_PRO);
        $service->startFor($member, $memberPlan);

        $service->cancelFor($member, atPeriodEnd: false);

        self::assertSame('canceled', $service->currentFor($member)['status']);
        self::assertSame('active', $service->currentFor($tenant)['status']);
        self::assertCount(2, $this->events($member));
        self::assertCount(1, $this->events($tenant));
    }

    public function testReconcileForWritesDriftAndASubjectScopedEvent(): void
    {
        $memberPlan = $this->seedPlan(['uuid' => 'planmember01']);
        $member = Subject::user('tenantA', 'user-1');

        $this->memberService()->startFor($member, $memberPlan, [
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_member_1',
        ]);

        $service = $this->service(
            new PermissiveSubjectResolver(),
            null,
            static fn (): array => ['status' => 'past_due', 'current_period_end' => '2026-09-01 00:00:00'],
        );

        $row = $service->reconcileFor($member);

        self::assertSame('past_due', $row['status']);
        self::assertSame('2026-09-01 00:00:00', $row['current_period_end']);

        $events = $this->events($member);
        self::assertCount(2, $events);
        self::assertSame('reconciled', $events[1]['type']);
        self::assertSame('user', $events[1]['subject_type']);
        self::assertSame('user-1', $events[1]['subject_uuid']);
    }

    // ---------------------------------------------------------------
    // Transactional lifecycle (spec §8)
    // ---------------------------------------------------------------

    public function testStartForRollsBackTheSubscriptionWhenTheEventAppendFails(): void
    {
        $service = $this->service(null, new ExplodingEventRepository());

        try {
            $service->startFor(Subject::tenant('tenantA'), self::PLATFORM_FREE);
            self::fail('Expected the event append failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('event append exploded', $e->getMessage());
        }

        self::assertSame(0, $this->connection()->table('subscriptions')->count());
        self::assertSame(0, $this->connection()->table('subscription_events')->count());
    }

    public function testChangePlanForRollsBackTheStateChangeWhenTheEventAppendFails(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->startFor($subject, self::PLATFORM_FREE);

        $exploding = $this->service(null, new ExplodingEventRepository());

        try {
            $exploding->changePlanFor($subject, self::PLATFORM_PRO);
            self::fail('Expected the event append failure to propagate.');
        } catch (\RuntimeException) {
        }

        $row = $this->service()->currentFor($subject);
        self::assertSame(self::PLATFORM_FREE, $row['plan_uuid']);
        self::assertSame('free', $row['plan_key']);
        self::assertCount(1, $this->events($subject));
    }

    public function testCancelForRollsBackTheStateChangeWhenTheEventAppendFails(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->startFor($subject, self::PLATFORM_FREE);

        try {
            $this->service(null, new ExplodingEventRepository())->cancelFor($subject, atPeriodEnd: false);
            self::fail('Expected the event append failure to propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame('active', $this->service()->currentFor($subject)['status']);
    }

    public function testReconcileForRollsBackTheDriftWriteWhenTheEventAppendFails(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->service()->startFor($subject, self::PLATFORM_FREE, [
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_1',
        ]);

        $service = $this->service(
            null,
            new ExplodingEventRepository(),
            static fn (): array => ['status' => 'past_due'],
        );

        try {
            $service->reconcileFor($subject);
            self::fail('Expected the event append failure to propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame('active', $this->service()->currentFor($subject)['status']);
    }

    // ---------------------------------------------------------------
    // Deterministic race (spec §8)
    // ---------------------------------------------------------------

    public function testLostRaceOnTheSamePlanReturnsTheWinnerRowIdempotently(): void
    {
        $subject = Subject::tenant('tenantA');
        // Pre-insert the WINNER: startFor's own insert now loses the subject unique.
        $winner = $this->seedSubscription([
            'uuid' => 'winner000001',
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'free',
        ]);

        $row = $this->service()->startFor($subject, self::PLATFORM_FREE);

        self::assertSame('winner000001', $row['uuid']);
        self::assertSame($winner['plan_uuid'], $row['plan_uuid']);
        self::assertSame(1, $this->connection()->table('subscriptions')->count());
        // Idempotent: the loser appends NO duplicate `created` event.
        self::assertCount(0, $this->events($subject));
    }

    public function testLostRaceOnADifferentPlanRaisesTheConflictAndChangesNothing(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->seedSubscription([
            'uuid' => 'winner000001',
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'free',
        ]);

        try {
            $this->service()->startFor($subject, self::PLATFORM_PRO);
            self::fail('Expected a SubscriptionConflictException.');
        } catch (SubscriptionConflictException) {
        }

        $rows = $this->connection()->table('subscriptions')->get();
        self::assertCount(1, $rows);
        self::assertSame('winner000001', $rows[0]['uuid']);
        self::assertSame(self::PLATFORM_FREE, $rows[0]['plan_uuid']);
        self::assertCount(0, $this->events($subject));
    }

    public function testTheSavepointLeavesTheOuterTransactionUsable(): void
    {
        // Spec §8: the insert is isolated by a NESTED transaction (savepoint) so a
        // unique violation never poisons the surrounding transaction -- the loser
        // still reads/writes afterwards. Proven here by the loser's own re-read
        // (findBySubject) succeeding inside the same outer transaction.
        $subject = Subject::tenant('tenantA');
        $this->seedSubscription(['uuid' => 'winner000001', 'tenant_uuid' => 'tenantA', 'plan_key' => 'free']);

        $service = $this->service();

        $row = db($this->appContext())->transaction(
            fn (): array => $service->startFor($subject, self::PLATFORM_FREE)
        );

        self::assertSame('winner000001', $row['uuid']);
        // The caller's outer transaction committed normally afterwards.
        self::assertSame(0, db($this->appContext())->transactionLevel());
    }

    public function testUnrelatedUniqueViolationIsNotSwallowedAsALostRace(): void
    {
        // The provider-subscription unique -- not the subject unique -- must
        // propagate untouched rather than being read as a lost subject race.
        $service = $this->service();
        $service->startFor(Subject::tenant('tenantA'), self::PLATFORM_FREE, [
            'provider_gateway' => 'stripe',
            'provider_subscription_id' => 'sub_shared',
        ]);

        try {
            $service->startFor(Subject::tenant('tenantB'), self::PLATFORM_FREE, [
                'provider_gateway' => 'stripe',
                'provider_subscription_id' => 'sub_shared',
            ]);
            self::fail('Expected the provider-subscription unique violation to propagate.');
        } catch (SubscriptionConflictException $e) {
            self::fail('A provider-subscription clash must not be reported as a subject conflict.');
        } catch (\Throwable $e) {
            self::assertTrue(
                str_contains(strtolower($e->getMessage()), 'unique'),
                'Expected the raw unique violation, got: ' . $e->getMessage()
            );
        }

        self::assertNull($this->service()->currentFor(Subject::tenant('tenantB')));
    }
}

/**
 * Fails the event append AFTER the state write, so the enclosing transaction is
 * the only thing that can undo the state change.
 */
final class ExplodingEventRepository extends SubscriptionEventRepository
{
    /** @param array<string,mixed> $event */
    public function insertOrThrow(ApplicationContext $context, array $event): void
    {
        throw new \RuntimeException('event append exploded');
    }
}
