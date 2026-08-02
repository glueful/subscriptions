<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Console;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Console\ReconcileCommand;
use Glueful\Extensions\Subscriptions\Console\SetPlanCommand;
use Glueful\Extensions\Subscriptions\Console\ShowSubscriptionCommand;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Extensions\Subscriptions\Tests\Support\CallablePuller;
use Glueful\Extensions\Subscriptions\Tests\Support\PermissiveSubjectResolver;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Task 6.5 -- the subscriptions:* ops commands (S8/S10), mirroring tenancy's
 * ConsoleCommandsTest: each command's protected context/container are re-pointed
 * at the migrated in-memory-SQLite harness via reflection so db()/app() inside
 * the command resolve the SAME connection/services the test seeded.
 */
final class ConsoleCommandsTest extends SubscriptionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The commands resolve SubscriptionService via app() -- bind one built
        // against the harness context.
        $this->bind(SubscriptionService::class, new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            new DefaultSubjectResolver()
        ));
    }

    private function bindCommand(Command $command): void
    {
        $ctx = $this->appContext();
        $container = $ctx->getContainer();

        $ref = new \ReflectionObject($command);
        $ctxProp = $ref->getProperty('context');
        $ctxProp->setAccessible(true);
        $ctxProp->setValue($command, $ctx);

        $containerProp = $ref->getProperty('container');
        $containerProp->setAccessible(true);
        $containerProp->setValue($command, $container);
    }

    public function testShowPrintsSubscriptionFields(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);

        $command = new ShowSubscriptionCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantA']);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('tenantA', $display);
        self::assertStringContainsString('pro', $display);
        self::assertStringContainsString('active', $display);
    }

    public function testShowReportsNoSubscription(): void
    {
        $command = new ShowSubscriptionCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'ghost']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No subscription', $tester->getDisplay());
    }

    public function testSetPlanChangesExistingPlan(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free', 'status' => 'active']);

        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantA', '--plan' => 'pro']);

        self::assertSame(Command::SUCCESS, $exit);
        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantA')->first();
        self::assertSame('pro', $row['plan_key']);
    }

    public function testSetPlanStartsWhenNoSubscriptionExists(): void
    {
        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantNew', '--plan' => 'pro']);

        self::assertSame(Command::SUCCESS, $exit);
        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantNew')->first();
        self::assertIsArray($row);
        self::assertSame('pro', $row['plan_key']);
        self::assertSame('active', $row['status']);
    }

    public function testSetPlanRejectsUnknownPlan(): void
    {
        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantA', '--plan' => 'platinum']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('platinum', $tester->getDisplay());
        self::assertNull($this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantA')->first());
    }

    public function testSetPlanAcceptsActiveDbPlan(): void
    {
        $this->seedManagedPlan('team', 'active');

        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantTeam', '--plan' => 'team']);

        self::assertSame(Command::SUCCESS, $exit);
        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantTeam')->first();
        self::assertSame('team', $row['plan_key']);
    }

    public function testSetPlanRejectsDraftDbPlan(): void
    {
        $this->seedManagedPlan('future', 'draft');

        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantFuture', '--plan' => 'future']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('not assignable', $tester->getDisplay());
        self::assertNull($this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantFuture')->first());
    }

    public function testSetPlanRejectsArchivedDbPlan(): void
    {
        $this->seedManagedPlan('legacy', 'archived');

        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantLegacy', '--plan' => 'legacy']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('not assignable', $tester->getDisplay());
        self::assertNull($this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantLegacy')->first());
    }

    public function testReconcileSingleTenantNoOpReportsSuccess(): void
    {
        // Non-payvia subscription with no payvia installed: reconcile is a no-op.
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free', 'status' => 'active']);

        $command = new ReconcileCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantA']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('tenantA', $tester->getDisplay());
    }

    public function testReconcileUnknownTenantFails(): void
    {
        $command = new ReconcileCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'ghost']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testReconcileAllIteratesProviderLinkedSubscriptions(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free']);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantB',
            'plan_key' => 'pro',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_X',
        ]);

        $command = new ReconcileCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        // Only the provider-linked subscription is iterated.
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // Task 13 -- --subject-type=/--subject-uuid= on the three commands.
    // ---------------------------------------------------------------

    public function testShowPrintsUserSubjectFields(): void
    {
        $this->seedMemberPlan('member', 'tenantA');
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
            'plan_key' => 'member',
            'status' => 'active',
        ]);

        $command = new ShowSubscriptionCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--tenant' => 'tenantA',
            '--subject-type' => 'user',
            '--subject-uuid' => 'user-1',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('user-1', $display);
        self::assertStringContainsString('member', $display);
    }

    public function testShowRejectsUserSubjectWithoutExplicitSubjectUuid(): void
    {
        $command = new ShowSubscriptionCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantA', '--subject-type' => 'user']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testShowRejectsUnknownSubjectType(): void
    {
        $command = new ShowSubscriptionCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--tenant' => 'tenantA',
            '--subject-type' => 'bogus',
            '--subject-uuid' => 'user-1',
        ]);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testShowRejectsMismatchedTenantSubjectUuid(): void
    {
        // --subject-type=tenant (explicit or defaulted) with a --subject-uuid
        // that disagrees with --tenant must fail closed rather than silently
        // querying a different (non-existent) subject and reporting a
        // false-positive "no subscription" success.
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'pro', 'status' => 'active']);

        $command = new ShowSubscriptionCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--tenant' => 'tenantA',
            '--subject-type' => 'tenant',
            '--subject-uuid' => 'tenantB',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString(
            '--subject-uuid must equal --tenant when --subject-type=tenant.',
            $tester->getDisplay()
        );
    }

    public function testSetPlanStartsUserSubjectWithinMemberCatalogScope(): void
    {
        $this->seedMemberPlan('member', 'tenantA');
        $this->rebindService(new PermissiveSubjectResolver());

        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--tenant' => 'tenantA',
            '--plan' => 'member',
            '--subject-type' => 'user',
            '--subject-uuid' => 'user-1',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $row = $this->connection()->table('subscriptions')
            ->where('tenant_uuid', 'tenantA')
            ->where('subject_type', 'user')
            ->where('subject_uuid', 'user-1')
            ->first();
        self::assertIsArray($row);
        self::assertSame('member', $row['plan_key']);
    }

    public function testSetPlanRejectsPlatformOnlyPlanForUserSubject(): void
    {
        $this->rebindService(new PermissiveSubjectResolver());

        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--tenant' => 'tenantA',
            '--plan' => 'pro',
            '--subject-type' => 'user',
            '--subject-uuid' => 'user-1',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('not assignable', $tester->getDisplay());
        self::assertNull(
            $this->connection()->table('subscriptions')
                ->where('tenant_uuid', 'tenantA')
                ->where('subject_type', 'user')
                ->first()
        );
    }

    public function testSetPlanRejectsMismatchedTenantSubjectUuidAndLeavesRowUnchanged(): void
    {
        // The real hazard: silently discarding a mismatched --subject-uuid and
        // writing through the tenant facade anyway, while the operator believes
        // the uuid disambiguated the target. Must fail closed before any write.
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free', 'status' => 'active']);

        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--tenant' => 'tenantA',
            '--plan' => 'pro',
            '--subject-type' => 'tenant',
            '--subject-uuid' => 'tenantB',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString(
            '--subject-uuid must equal --tenant when --subject-type=tenant.',
            $tester->getDisplay()
        );
        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantA')->first();
        self::assertIsArray($row);
        self::assertSame('free', $row['plan_key']);
    }

    public function testSetPlanRejectsUserSubjectWithoutExplicitSubjectUuid(): void
    {
        $command = new SetPlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--tenant' => 'tenantA',
            '--plan' => 'free',
            '--subject-type' => 'user',
        ]);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testReconcileRejectsMismatchedTenantSubjectUuid(): void
    {
        // Must fail closed with the brief's one-line, non-zero-exit error --
        // not an uncaught InvalidArgumentException from an incoherent Subject
        // reaching reconcileFor().
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free', 'status' => 'active']);

        $command = new ReconcileCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--tenant' => 'tenantA',
            '--subject-type' => 'tenant',
            '--subject-uuid' => 'tenantB',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString(
            '--subject-uuid must equal --tenant when --subject-type=tenant.',
            $tester->getDisplay()
        );
        $row = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantA')->first();
        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
    }

    public function testReconcileSingleUserSubjectNoOpReportsSuccess(): void
    {
        $this->seedMemberPlan('member', 'tenantA');
        $this->rebindService(new PermissiveSubjectResolver());
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
            'plan_key' => 'member',
            'status' => 'active',
        ]);

        $command = new ReconcileCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--tenant' => 'tenantA',
            '--subject-type' => 'user',
            '--subject-uuid' => 'user-1',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('user-1', $tester->getDisplay());
    }

    public function testReconcileRejectsUserSubjectWithoutExplicitSubjectUuid(): void
    {
        $command = new ReconcileCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--tenant' => 'tenantA', '--subject-type' => 'user']);

        self::assertSame(Command::FAILURE, $exit);
    }

    /**
     * Pinned reconcile-all correctness: a mixed fixture (one tenant + one user
     * subscription, same workspace) must have EACH ROW reconciled through its
     * own exact subject -- never the tenant facade for the user row. Proven by
     * feeding a puller that returns a DIFFERENT drift per provider-subscription
     * id: if the user row were ever reconciled via the tenant facade (or not
     * reconciled through its own subject at all), it would keep its original
     * status instead of picking up its OWN drift.
     */
    public function testReconcileAllReconstructsExactSubjectPerRowNoCrossTargeting(): void
    {
        $this->seedMemberPlan('member', 'tenantA');

        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'plan_key' => 'free',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_tenant',
        ]);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'user',
            'subject_uuid' => 'user-1',
            'plan_key' => 'member',
            'status' => 'active',
            'provider_gateway' => 'paystack',
            'provider_subscription_id' => 'sub_user',
        ]);

        $this->rebindService(new PermissiveSubjectResolver(), function (string $gateway, string $id): ?array {
            return match ($id) {
                'sub_tenant' => ['status' => 'past_due', 'current_period_end' => '2026-09-01 00:00:00'],
                'sub_user' => ['status' => 'canceled', 'current_period_end' => '2026-09-01 00:00:00'],
                default => null,
            };
        });

        $command = new ReconcileCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('2', $tester->getDisplay());

        $tenantRow = $this->connection()->table('subscriptions')
            ->where('tenant_uuid', 'tenantA')
            ->where('subject_type', 'tenant')
            ->where('subject_uuid', 'tenantA')
            ->first();
        $userRow = $this->connection()->table('subscriptions')
            ->where('tenant_uuid', 'tenantA')
            ->where('subject_type', 'user')
            ->where('subject_uuid', 'user-1')
            ->first();

        self::assertIsArray($tenantRow);
        self::assertIsArray($userRow);
        self::assertSame('past_due', $tenantRow['status']);
        self::assertSame('canceled', $userRow['status']);
    }

    private function rebindService(SubjectResolverInterface $resolver, ?callable $puller = null): void
    {
        $this->bind(SubscriptionService::class, new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->appContext()),
            $this->appContext(),
            $resolver,
            $puller !== null ? new CallablePuller($puller) : null
        ));
    }

    private function seedMemberPlan(string $planKey, string $tenantUuid): void
    {
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => Utils::generateNanoID(12),
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'description' => null,
            'entitlements' => json_encode(['posts.premium' => true], JSON_THROW_ON_ERROR),
            'provider_price_id' => null,
            'status' => 'active',
            'sort_order' => 0,
            'audience' => 'user',
            'owner_tenant_uuid' => $tenantUuid,
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
        ]);
    }

    private function seedManagedPlan(string $planKey, string $status): void
    {
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => Utils::generateNanoID(12),
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'description' => null,
            'entitlements' => json_encode(['projects.limit' => 25], JSON_THROW_ON_ERROR),
            'provider_price_id' => null,
            'status' => $status,
            'sort_order' => 10,
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
        ]);
    }
}
