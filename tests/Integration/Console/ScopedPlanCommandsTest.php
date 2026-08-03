<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Console;

use Glueful\Extensions\Subscriptions\Console\Plans\ArchivePlanCommand;
use Glueful\Extensions\Subscriptions\Console\Plans\CreatePlanCommand;
use Glueful\Extensions\Subscriptions\Console\Plans\ImportConfigPlansCommand;
use Glueful\Extensions\Subscriptions\Console\Plans\ListPlansCommand;
use Glueful\Extensions\Subscriptions\Console\Plans\UpdatePlanCommand;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Task 8: `plans:*` console commands gain `--audience=`/`--owner=` options,
 * defaulting to the platform scope ('tenant', ''). Runs on the post-006
 * SubscriptionsTestCase harness. The untouched, no-option PlanCommandsTest
 * (shared 1.x harness) proves the no-option path stays byte-identical.
 */
final class ScopedPlanCommandsTest extends SubscriptionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bind(PlanManagementService::class, new PlanManagementService(
            $this->appContext(),
            new SubscriptionPlanRepository(),
            new PlanPayloadValidator()
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

    public function testCreateWithAudienceAndOwnerWritesScopedRow(): void
    {
        $command = new CreatePlanCommand();
        $this->bindCommand($command);

        $exit = (new CommandTester($command))->execute([
            '--key' => 'member',
            '--name' => 'Member',
            '--entitlements' => '{"projects.limit":3}',
            '--audience' => 'user',
            '--owner' => 'workspace-1',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $row = $this->scopedPlan('member', 'user', 'workspace-1');
        self::assertSame('Member', $row['display_name']);
    }

    public function testCreateWithOnlyOwnerDefaultsAudienceToPlatformInvalidCombo(): void
    {
        // --owner without --audience defaults audience to the platform value
        // 'tenant', which combined with a non-empty owner violates the scope
        // invariant and must fail cleanly (not silently write a bad row).
        $command = new CreatePlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--key' => 'member',
            '--name' => 'Member',
            '--entitlements' => '{"projects.limit":3}',
            '--owner' => 'workspace-1',
        ]);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testCreateWithoutScopeOptionsIsOneXIdentical(): void
    {
        $command = new CreatePlanCommand();
        $this->bindCommand($command);

        $exit = (new CommandTester($command))->execute([
            '--key' => 'team',
            '--name' => 'Team',
            '--entitlements' => '{"projects.limit":10}',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $row = $this->connection()->table('subscription_plans')->where('plan_key', 'team')->first();
        self::assertSame('tenant', $row['audience']);
        self::assertSame('', $row['owner_tenant_uuid']);
    }

    public function testUpdateWithAudienceAndOwnerUpdatesOnlyScopedRow(): void
    {
        $this->createScopedPlan('pro', 'user', 'workspace-1');

        $command = new UpdatePlanCommand();
        $this->bindCommand($command);

        $exit = (new CommandTester($command))->execute([
            '--key' => 'pro',
            '--audience' => 'user',
            '--owner' => 'workspace-1',
            '--name' => 'Workspace Pro',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('Workspace Pro', $this->scopedPlan('pro', 'user', 'workspace-1')['display_name']);
        // The platform 'pro' plan (seeded by SubscriptionsTestCase) is untouched.
        self::assertSame('Pro', $this->scopedPlan('pro', 'tenant', '')['display_name']);
    }

    public function testArchiveWithAudienceAndOwnerArchivesOnlyScopedRow(): void
    {
        $this->createScopedPlan('pro', 'user', 'workspace-1');

        $command = new ArchivePlanCommand();
        $this->bindCommand($command);

        $exit = (new CommandTester($command))->execute([
            '--key' => 'pro',
            '--audience' => 'user',
            '--owner' => 'workspace-1',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('archived', $this->scopedPlan('pro', 'user', 'workspace-1')['status']);
        self::assertSame('active', $this->scopedPlan('pro', 'tenant', '')['status']);
    }

    public function testListWithAudienceAndOwnerListsOnlyScopedRows(): void
    {
        $this->createScopedPlan('member', 'user', 'workspace-1');

        $command = new ListPlansCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--audience' => 'user', '--owner' => 'workspace-1']);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('member', $display);
        self::assertStringNotContainsString('free', $display);
        self::assertStringNotContainsString('pro', $display);
    }

    public function testImportConfigHasNoScopeOptions(): void
    {
        $command = new ImportConfigPlansCommand();

        self::assertFalse($command->getDefinition()->hasOption('audience'));
        self::assertFalse($command->getDefinition()->hasOption('owner'));
    }

    /** @param array<string,mixed> $overrides */
    private function createScopedPlan(string $key, string $audience, string $owner, array $overrides = []): void
    {
        $this->appContext()->getContainer()->get(PlanManagementService::class)->createInScope(
            $audience,
            $owner,
            array_merge([
                'plan_key' => $key,
                'display_name' => ucfirst($key),
                'entitlements' => ['projects.limit' => 10],
                'status' => 'active',
            ], $overrides)
        );
    }

    /** @return array<string,mixed> */
    private function scopedPlan(string $key, string $audience, string $owner): array
    {
        $row = $this->connection()->table('subscription_plans')
            ->where('plan_key', '=', $key)
            ->where('audience', '=', $audience)
            ->where('owner_tenant_uuid', '=', $owner)
            ->first();
        self::assertIsArray($row);

        return $row;
    }
}
