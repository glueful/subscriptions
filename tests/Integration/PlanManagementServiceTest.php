<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\CapturingLogger;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
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
            'provider_price_id' => 'price1234567',
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

    public function testPatchUpdatesFieldsStatusProviderSortOrderAndEntitlements(): void
    {
        $this->service->create(array_merge($this->payload('team'), ['status' => 'draft']));

        $row = $this->service->update('team', [
            'display_name' => 'Team Plus',
            'description' => 'Updated',
            'entitlements' => ['projects.limit' => 50, 'reports.export' => true],
            'provider_price_id' => 'price7654321',
            'status' => 'active',
            'sort_order' => 30,
        ]);

        self::assertSame('Team Plus', $row['display_name']);
        self::assertSame('Updated', $row['description']);
        self::assertSame(['projects.limit' => 50, 'reports.export' => true], $row['entitlements']);
        self::assertSame('price7654321', $row['provider_price_id']);
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

    public function testCreateAuditCapturesCreatedAction(): void
    {
        $logger = new CapturingLogger();
        $this->bind(LoggerInterface::class, $logger);

        $this->service->create($this->payload('team'));

        self::assertSame('subscriptions.plan_changed', $logger->records[0]['message']);
        self::assertSame('team', $logger->records[0]['context']['plan_key']);
        self::assertSame('created', $logger->records[0]['context']['action']);
        self::assertSame([], $logger->records[0]['context']['before']);
        self::assertSame('team', $logger->records[0]['context']['after']['plan_key']);
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

    public function testForceImportOverwritesEntitlementsAndProviderLink(): void
    {
        $this->setConfig('subscriptions.plans.pro.provider_price_id', 'configPrice1');
        $this->service->create(array_merge($this->payload('pro'), [
            'entitlements' => ['projects.limit' => 999],
            'provider_price_id' => 'oldPrice0001',
        ]));

        $this->service->importConfig(force: true);
        $row = $this->service->find('pro');

        self::assertSame(50, $row['entitlements']['projects.limit']);
        self::assertSame('configPrice1', $row['provider_price_id']);
    }

    public function testPatchAuditCapturesBeforeAfterAndDiff(): void
    {
        $logger = new CapturingLogger();
        $this->bind(LoggerInterface::class, $logger);
        $this->service->create($this->payload('team'));
        $logger->records = [];

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

    public function testArchiveAuditCapturesArchivedAction(): void
    {
        $logger = new CapturingLogger();
        $this->bind(LoggerInterface::class, $logger);
        $this->service->create($this->payload('team'));
        $logger->records = [];

        $this->service->archive('team');

        self::assertSame('subscriptions.plan_changed', $logger->records[0]['message']);
        self::assertSame('team', $logger->records[0]['context']['plan_key']);
        self::assertSame('archived', $logger->records[0]['context']['action']);
        self::assertSame('active', $logger->records[0]['context']['before']['status']);
        self::assertSame('archived', $logger->records[0]['context']['after']['status']);
    }

    public function testMissingLoggerDoesNotFail(): void
    {
        $this->service->create($this->payload('team'));

        $row = $this->service->update('team', ['display_name' => 'Team Plus']);

        self::assertSame('Team Plus', $row['display_name']);
    }
}
