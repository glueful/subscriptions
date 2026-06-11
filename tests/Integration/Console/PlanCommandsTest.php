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

final class PlanCommandsTest extends SubscriptionsTestCase
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

    public function testCreateCreatesActivePlanFromInlineEntitlements(): void
    {
        $command = new CreatePlanCommand();
        $this->bindCommand($command);

        $exit = (new CommandTester($command))->execute([
            '--key' => 'team',
            '--name' => 'Team',
            '--entitlements' => '{"projects.limit":10}',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('team', $this->plan('team')['plan_key']);
    }

    public function testCreateAcceptsEntitlementsFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'entitlements_');
        file_put_contents($file, '{"projects.limit":25}');

        $command = new CreatePlanCommand();
        $this->bindCommand($command);

        $exit = (new CommandTester($command))->execute([
            '--key' => 'team',
            '--name' => 'Team',
            '--entitlements-file' => $file,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['projects.limit' => 25], json_decode((string) $this->plan('team')['entitlements'], true));
    }

    public function testCreateRejectsInvalidEntitlementValue(): void
    {
        $command = new CreatePlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--key' => 'team',
            '--name' => 'Team',
            '--entitlements' => '{"projects.limit":-1}',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('non-negative', $tester->getDisplay());
    }

    public function testCreateRejectsMalformedEntitlementsJsonCleanly(): void
    {
        $command = new CreatePlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--key' => 'team',
            '--name' => 'Team',
            '--entitlements' => '{"projects.limit":',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('valid JSON', $tester->getDisplay());
    }

    public function testUpdateChangesEntitlementsAndRejectsActiveToDraft(): void
    {
        $this->createPlan('team');

        $update = new UpdatePlanCommand();
        $this->bindCommand($update);

        $exit = (new CommandTester($update))->execute([
            '--key' => 'team',
            '--entitlements' => '{"projects.limit":99}',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['projects.limit' => 99], json_decode((string) $this->plan('team')['entitlements'], true));

        $tester = new CommandTester($update);
        $exit = $tester->execute(['--key' => 'team', '--status' => 'draft']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('draft', $tester->getDisplay());
    }

    public function testUpdateRejectsMalformedEntitlementsJsonCleanly(): void
    {
        $this->createPlan('team');

        $command = new UpdatePlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--key' => 'team',
            '--entitlements' => '{"projects.limit":',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('valid JSON', $tester->getDisplay());
    }

    public function testUpdateRejectsArchivedToDraft(): void
    {
        $this->createPlan('team');
        $this->service()->archive('team');

        $command = new UpdatePlanCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--key' => 'team', '--status' => 'draft']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('draft', $tester->getDisplay());
    }

    public function testArchiveArchivesPlan(): void
    {
        $this->createPlan('team');

        $command = new ArchivePlanCommand();
        $this->bindCommand($command);

        $exit = (new CommandTester($command))->execute(['--key' => 'team']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('archived', $this->plan('team')['status']);
    }

    public function testImportConfigCreatesMissingPlansAndDoesNotOverwriteWithoutForce(): void
    {
        $this->createPlan('pro', ['display_name' => 'Existing Pro', 'entitlements' => ['projects.limit' => 999]]);

        $command = new ImportConfigPlansCommand();
        $this->bindCommand($command);

        $exit = (new CommandTester($command))->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotNull($this->plan('free'));
        self::assertSame('Existing Pro', $this->plan('pro')['display_name']);
    }

    public function testImportConfigWithForceOverwrites(): void
    {
        $this->createPlan('pro', ['entitlements' => ['projects.limit' => 999]]);

        $command = new ImportConfigPlansCommand();
        $this->bindCommand($command);

        $exit = (new CommandTester($command))->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(50, json_decode((string) $this->plan('pro')['entitlements'], true)['projects.limit']);
    }

    public function testListPrintsPlanFields(): void
    {
        $this->createPlan('team', ['payvia_priced_plan_uuid' => 'price1234567']);

        $command = new ListPlansCommand();
        $this->bindCommand($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('team', $display);
        self::assertStringContainsString('Team', $display);
        self::assertStringContainsString('active', $display);
        self::assertStringContainsString('price1234567', $display);
    }

    /** @param array<string,mixed> $overrides */
    private function createPlan(string $key, array $overrides = []): void
    {
        $this->service()->create(array_merge([
            'plan_key' => $key,
            'display_name' => ucfirst($key),
            'entitlements' => ['projects.limit' => 10],
            'status' => 'active',
        ], $overrides));
    }

    /** @return array<string,mixed> */
    private function plan(string $key): array
    {
        $row = $this->connection()->table('subscription_plans')->where('plan_key', $key)->first();
        self::assertIsArray($row);

        return $row;
    }

    private function service(): PlanManagementService
    {
        return $this->appContext()->getContainer()->get(PlanManagementService::class);
    }
}
