<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Console;

use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Console\ReconcileCommand;
use Glueful\Extensions\Subscriptions\Console\SetPlanCommand;
use Glueful\Extensions\Subscriptions\Console\ShowSubscriptionCommand;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;
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
            $this->appContext()
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

    public function testReconcileAllIteratesPayviaLinkedSubscriptions(): void
    {
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'free']);
        $this->seedSubscription([
            'tenant_uuid' => 'tenantB',
            'plan_key' => 'pro',
            'payvia_gateway' => 'paystack',
            'payvia_subscription_id' => 'sub_X',
        ]);

        $command = new ReconcileCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        // Only the payvia-linked subscription is iterated.
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    private function seedManagedPlan(string $planKey, string $status): void
    {
        $this->connection()->table('subscription_plans')->insert([
            'uuid' => Utils::generateNanoID(12),
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'description' => null,
            'entitlements' => json_encode(['projects.limit' => 25], JSON_THROW_ON_ERROR),
            'payvia_priced_plan_uuid' => null,
            'status' => $status,
            'sort_order' => 10,
            'created_at' => '2026-06-10 10:00:00',
            'updated_at' => '2026-06-10 10:00:00',
        ]);
    }
}
