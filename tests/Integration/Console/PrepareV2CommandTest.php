<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Console;

use Glueful\Extensions\Subscriptions\Console\PrepareV2Command;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\CapturingLogger;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PrepareV2CommandTest extends SubscriptionsTestCase
{
    private CapturingLogger $recordingAudit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bind(PlanManagementService::class, new PlanManagementService(
            $this->appContext(),
            new SubscriptionPlanRepository(),
            new PlanPayloadValidator()
        ));
        $this->bind(SubscriptionPlanRepository::class, new SubscriptionPlanRepository());

        $this->recordingAudit = new CapturingLogger();
        $this->bind(LoggerInterface::class, $this->recordingAudit);
    }

    public function testPreparesConfigPlansSynthesizesDanglingAndWritesMarker(): void
    {
        // 1.x-shaped state: a config-only catalog (free/pro seeded as config in
        // SubscriptionsTestCase), one DB plan, one subscription on a config plan,
        // one subscription on a DANGLING key that exists nowhere.
        db($this->context)->table('subscription_plans')->insert([
            'uuid' => 'planaaaa0001', 'plan_key' => 'enterprise', 'display_name' => 'Enterprise',
            'entitlements' => json_encode(['x' => true]), 'status' => 'active', 'sort_order' => 0,
        ]);
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 'subaaaa00001', 'tenant_uuid' => 't-1', 'plan_key' => 'pro', 'status' => 'active',
        ]);
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 'subaaaa00002', 'tenant_uuid' => 't-2', 'plan_key' => 'legacy-gold', 'status' => 'active',
        ]);

        $exit = $this->runPrepare(); // helper: executes PrepareV2Command via CommandTester
        self::assertSame(0, $exit);

        // Config plans imported (create-missing): free + pro now DB rows.
        foreach (['free', 'pro'] as $key) {
            self::assertNotNull(
                (new SubscriptionPlanRepository())->findByKey($this->context, $key),
                "config plan {$key} not imported"
            );
        }
        // Dangling key synthesized as archived, empty entitlements.
        $legacy = (new SubscriptionPlanRepository())->findByKey($this->context, 'legacy-gold');
        self::assertSame('archived', $legacy['status']);
        self::assertSame([], $legacy['entitlements']); // repository rows are already decoded

        // Exactly one marker with a report naming the synthesis.
        $markers = db($this->context)->table('subscription_v2_preparation')->get();
        self::assertCount(1, $markers);
        self::assertSame('subject-model-v2', $markers[0]['marker_key']);
        $report = json_decode((string) $markers[0]['report'], true);
        self::assertContains('legacy-gold', $report['synthesized']);
    }

    public function testRerunIsIdempotentAndReplacesTheMarker(): void
    {
        $this->runPrepare();
        $first = db($this->context)->table('subscription_v2_preparation')->get()[0];
        $this->runPrepare();
        $rows = db($this->context)->table('subscription_v2_preparation')->get();
        self::assertCount(1, $rows);                       // still exactly one marker
        self::assertNotSame($first['id'], $rows[0]['id']); // replaced, not kept
        // No duplicate plans were created by the second run.
        $keys = array_column(db($this->context)->table('subscription_plans')->get(), 'plan_key');
        self::assertSame(count($keys), count(array_unique($keys)));
    }

    public function testFailedVerificationLeavesNoMarker(): void
    {
        // Force failure: insert a subscription AFTER import would run, via a
        // repository fake is overkill — instead point the command at a validator
        // hook: simplest deterministic failure is a subscription whose plan_key is
        // empty string, which import/synthesis cannot resolve.
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 'subaaaa00003', 'tenant_uuid' => 't-3', 'plan_key' => '', 'status' => 'active',
        ]);
        $exit = $this->runPrepare();
        self::assertNotSame(0, $exit);
        self::assertCount(0, db($this->context)->table('subscription_v2_preparation')->get());
        self::assertNull((new SubscriptionPlanRepository())->findByKey($this->context, 'free'));
        self::assertNull((new SubscriptionPlanRepository())->findByKey($this->context, 'pro'));
        self::assertSame([], $this->recordingAudit->records());
    }

    private function runPrepare(): int
    {
        $command = new PrepareV2Command();
        $this->bindCommand($command);

        return (new CommandTester($command))->execute([]);
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
}
