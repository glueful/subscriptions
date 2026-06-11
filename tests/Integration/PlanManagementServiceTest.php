<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class PlanManagementServiceTest extends SubscriptionsTestCase
{
    private PlanManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlanManagementService(
            $this->appContext(),
            new SubscriptionPlanRepository(),
            new PlanPayloadValidator()
        );
    }

    /** @return array<string,mixed> */
    private function payload(string $planKey = 'pro'): array
    {
        return [
            'plan_key' => $planKey,
            'display_name' => ucfirst($planKey),
            'description' => 'Managed plan',
            'entitlements' => ['projects.limit' => 10],
            'payvia_priced_plan_uuid' => 'price1234567',
            'status' => 'active',
            'sort_order' => 10,
        ];
    }

    public function testCreatePersistsNormalizedPayloadAndUuid(): void
    {
        $row = $this->service->create($this->payload('team'));

        self::assertSame('team', $row['plan_key']);
        self::assertSame(12, strlen((string) $row['uuid']));
        self::assertSame(['projects.limit' => 10], $row['entitlements']);

        $stored = $this->connection()->table('subscription_plans')->where('plan_key', 'team')->first();
        self::assertIsArray($stored);
        self::assertSame('Team', $stored['display_name']);
    }

    public function testCreateDuplicatePlanKeyFailsCleanly(): void
    {
        $this->service->create($this->payload('team'));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($this->payload('team'));
    }

    public function testPatchUpdatesFieldsStatusPayviaSortOrderAndEntitlements(): void
    {
        $this->service->create(array_merge($this->payload('team'), ['status' => 'draft']));

        $row = $this->service->update('team', [
            'display_name' => 'Team Plus',
            'description' => 'Updated',
            'entitlements' => ['projects.limit' => 50, 'reports.export' => true],
            'payvia_priced_plan_uuid' => 'price7654321',
            'status' => 'active',
            'sort_order' => 30,
        ]);

        self::assertSame('Team Plus', $row['display_name']);
        self::assertSame('Updated', $row['description']);
        self::assertSame(['projects.limit' => 50, 'reports.export' => true], $row['entitlements']);
        self::assertSame('price7654321', $row['payvia_priced_plan_uuid']);
        self::assertSame('active', $row['status']);
        self::assertSame(30, (int) $row['sort_order']);
    }

    public function testPatchRejectsPublishedPlanReturningToDraft(): void
    {
        $this->service->create($this->payload('team'));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->update('team', ['status' => 'draft']);
    }

    public function testArchiveSetsStatusAndDoesNotMutateSubscriptions(): void
    {
        $this->service->create($this->payload('team'));
        $this->seedSubscription(['tenant_uuid' => 'tenantA', 'plan_key' => 'team']);

        $row = $this->service->archive('team');

        self::assertSame('archived', $row['status']);
        $subscription = $this->connection()->table('subscriptions')->where('tenant_uuid', 'tenantA')->first();
        self::assertSame('team', $subscription['plan_key']);
    }

    public function testImportConfigCreatesMissingPlansWithoutOverwritingExistingRows(): void
    {
        $this->service->create(array_merge($this->payload('pro'), [
            'display_name' => 'Existing Pro',
            'entitlements' => ['projects.limit' => 999],
        ]));

        $imported = $this->service->importConfig(force: false);

        self::assertContains('free', array_column($imported, 'plan_key'));
        self::assertSame('Existing Pro', $this->service->find('pro')['display_name']);
        self::assertSame(['projects.limit' => 999], $this->service->find('pro')['entitlements']);
    }

    public function testForceImportOverwritesEntitlementsAndPayviaLink(): void
    {
        $this->setConfig('subscriptions.plans.pro.payvia_priced_plan', 'configPrice1');
        $this->service->create(array_merge($this->payload('pro'), [
            'entitlements' => ['projects.limit' => 999],
            'payvia_priced_plan_uuid' => 'oldPrice0001',
        ]));

        $this->service->importConfig(force: true);
        $row = $this->service->find('pro');

        self::assertSame(50, $row['entitlements']['projects.limit']);
        self::assertSame('configPrice1', $row['payvia_priced_plan_uuid']);
    }

    public function testPatchAuditCapturesBeforeAfterAndDiff(): void
    {
        $logger = new CapturingLogger();
        $this->bind(LoggerInterface::class, $logger);
        $this->service->create($this->payload('team'));

        $this->service->update('team', ['display_name' => 'Team Plus']);

        self::assertSame('subscriptions.plan_changed', $logger->records[0]['message']);
        self::assertSame('team', $logger->records[0]['context']['plan_key']);
        self::assertSame('updated', $logger->records[0]['context']['action']);
        self::assertSame('Team', $logger->records[0]['context']['before']['display_name']);
        self::assertSame('Team Plus', $logger->records[0]['context']['after']['display_name']);
        self::assertSame(
            ['before' => 'Team', 'after' => 'Team Plus'],
            $logger->records[0]['context']['diff']['display_name']
        );
    }

    public function testMissingLoggerDoesNotFail(): void
    {
        $this->service->create($this->payload('team'));

        $row = $this->service->update('team', ['display_name' => 'Team Plus']);

        self::assertSame('Team Plus', $row['display_name']);
    }
}

final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
    public array $records = [];

    /** @param array<string,mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
