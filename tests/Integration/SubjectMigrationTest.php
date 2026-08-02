<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration;

use Glueful\Extensions\Subscriptions\Console\PrepareV2Command;
use Glueful\Extensions\Subscriptions\Database\Migrations\SubjectModel;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionPlanRepository;
use Glueful\Extensions\Subscriptions\Tests\Support\LegacySchemaTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives migration 006 explicitly against 1.x-shaped fixtures on the SHARED
 * (001-005) harness. 006 is deliberately NOT added to SubscriptionsTestCase
 * (Task 9's coordinated activation boundary) -- every test here constructs
 * SubjectModel itself and calls up()/down() directly.
 */
final class SubjectMigrationTest extends LegacySchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bind(PlanManagementService::class, new PlanManagementService(
            $this->appContext(),
            new SubscriptionPlanRepository(),
            new PlanPayloadValidator()
        ));
        $this->bind(SubscriptionPlanRepository::class, new SubscriptionPlanRepository());
    }

    public function testRefusesPopulatedInstallWithoutPreparationMarker(): void
    {
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's1', 'tenant_uuid' => 't-1', 'plan_key' => 'pro', 'status' => 'active',
        ]);
        $this->expectException(\RuntimeException::class);
        (new SubjectModel())->up($this->connection->getSchemaBuilder());
    }

    public function testUpgradesPreparedInstall(): void
    {
        $this->preparedFixture(); // runs the REAL PrepareV2Command (Task 2) on a seeded 1.x dataset
        (new SubjectModel())->up($this->connection->getSchemaBuilder());

        $sub = db($this->context)->table('subscriptions')->where('tenant_uuid', '=', 't-1')->first();
        self::assertSame('tenant', $sub['subject_type']);
        self::assertSame('t-1', $sub['subject_uuid']);
        self::assertNotEmpty($sub['plan_uuid']); // backfilled and NOT NULL

        // old UNIQUE(tenant_uuid) gone: a user-subject row for the same tenant inserts fine
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's9', 'tenant_uuid' => 't-1', 'subject_type' => 'user', 'subject_uuid' => 'u-1',
            'plan_key' => 'pro', 'plan_uuid' => $sub['plan_uuid'], 'status' => 'active',
        ]);

        // new triple unique: duplicate subject throws
        $this->expectException(\Throwable::class);
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's10', 'tenant_uuid' => 't-1', 'subject_type' => 'user', 'subject_uuid' => 'u-1',
            'plan_key' => 'pro', 'plan_uuid' => $sub['plan_uuid'], 'status' => 'active',
        ]);
    }

    public function testFreshInstallSkipsMarkerRequirement(): void
    {
        // Zero subscriptions, no preparation marker: the guard must not block a fresh install.
        (new SubjectModel())->up($this->connection->getSchemaBuilder());

        $schema = $this->connection->getSchemaBuilder();
        self::assertTrue($schema->hasTable('subscription_provider_event_receipts'));
        self::assertTrue($schema->hasColumn('subscriptions', 'plan_uuid'));
        self::assertTrue($schema->hasColumn('subscriptions', 'subject_type'));
        self::assertTrue($schema->hasColumn('subscriptions', 'subject_uuid'));
        self::assertTrue($schema->hasColumn('subscription_overrides', 'subject_type'));
        self::assertTrue($schema->hasColumn('subscription_events', 'subject_type'));
        self::assertTrue($schema->hasColumn('subscription_plans', 'audience'));
        self::assertTrue($schema->hasColumn('subscription_plans', 'owner_tenant_uuid'));
    }

    public function testEventsBackfillIntoAcceptedReceipts(): void
    {
        db($this->context)->table('subscription_events')->insert([
            'uuid' => 'evt00000001', 'tenant_uuid' => 't-1', 'type' => 'subscription.created',
            'source' => 'provider_event', 'provider_gateway' => 'stripe',
            'provider_logical_event_key' => 'subscription.created:sub_1:v1',
        ]);
        // Manual event (no provider gateway/key): must NOT be copied into a receipt.
        db($this->context)->table('subscription_events')->insert([
            'uuid' => 'evt00000002', 'tenant_uuid' => 't-1', 'type' => 'manual',
            'source' => 'manual', 'provider_gateway' => null, 'provider_logical_event_key' => null,
        ]);

        (new SubjectModel())->up($this->connection->getSchemaBuilder());

        $receipts = db($this->context)->table('subscription_provider_event_receipts')->get();
        self::assertCount(1, $receipts);
        self::assertSame('evt00000001', $receipts[0]['uuid']);
        self::assertSame('stripe', $receipts[0]['provider_gateway']);
        self::assertSame('subscription.created:sub_1:v1', $receipts[0]['provider_logical_event_key']);
        self::assertSame('subscription.created', $receipts[0]['event_type']);
        self::assertSame('accepted', $receipts[0]['outcome']);
        self::assertSame('t-1', $receipts[0]['tenant_uuid']);
        self::assertSame('tenant', $receipts[0]['subject_type']);
        self::assertSame('t-1', $receipts[0]['subject_uuid']);
    }

    public function testPlansGainScopeColumnsAndScopedUnique(): void
    {
        db($this->context)->table('subscription_plans')->insert([
            'uuid' => 'planaaaa0001', 'plan_key' => 'pro', 'display_name' => 'Pro',
            'entitlements' => json_encode(['x' => true], JSON_THROW_ON_ERROR),
            'status' => 'active', 'sort_order' => 0,
        ]);

        (new SubjectModel())->up($this->connection->getSchemaBuilder());

        $platform = db($this->context)->table('subscription_plans')
            ->where('plan_key', '=', 'pro')
            ->where('owner_tenant_uuid', '=', '')
            ->first();
        self::assertSame('tenant', $platform['audience']);
        self::assertSame('', $platform['owner_tenant_uuid']);

        // Old UNIQUE(plan_key) is gone: platform pro + two DIFFERENT workspaces' pro coexist.
        db($this->context)->table('subscription_plans')->insert([
            'uuid' => 'planaaaa0002', 'plan_key' => 'pro', 'display_name' => 'Pro (ws-1)',
            'entitlements' => json_encode(['y' => true], JSON_THROW_ON_ERROR),
            'status' => 'active', 'sort_order' => 0,
            'audience' => 'user', 'owner_tenant_uuid' => 'ws-1',
        ]);
        db($this->context)->table('subscription_plans')->insert([
            'uuid' => 'planaaaa0003', 'plan_key' => 'pro', 'display_name' => 'Pro (ws-2)',
            'entitlements' => json_encode(['z' => true], JSON_THROW_ON_ERROR),
            'status' => 'active', 'sort_order' => 0,
            'audience' => 'user', 'owner_tenant_uuid' => 'ws-2',
        ]);
        self::assertSame(3, db($this->context)->table('subscription_plans')->count());

        // New scoped unique still guards duplicates within the same (audience, owner, key) scope.
        $this->expectException(\Throwable::class);
        db($this->context)->table('subscription_plans')->insert([
            'uuid' => 'planaaaa0004', 'plan_key' => 'pro', 'display_name' => 'Pro (ws-1 dup)',
            'entitlements' => json_encode(['w' => true], JSON_THROW_ON_ERROR),
            'status' => 'active', 'sort_order' => 0,
            'audience' => 'user', 'owner_tenant_uuid' => 'ws-1',
        ]);
    }

    public function testOverridesUniqueBecomesSubjectScoped(): void
    {
        db($this->context)->table('subscription_overrides')->insert([
            'uuid' => 'ovr00000001', 'tenant_uuid' => 't-1', 'entitlement' => 'projects.limit',
            'value' => json_encode(10, JSON_THROW_ON_ERROR),
        ]);

        (new SubjectModel())->up($this->connection->getSchemaBuilder());

        // Old UNIQUE(tenant_uuid, entitlement) gone: a user-subject override for the
        // same tenant+entitlement inserts fine.
        db($this->context)->table('subscription_overrides')->insert([
            'uuid' => 'ovr00000002', 'tenant_uuid' => 't-1', 'subject_type' => 'user',
            'subject_uuid' => 'u-1', 'entitlement' => 'projects.limit',
            'value' => json_encode(20, JSON_THROW_ON_ERROR),
        ]);

        // New subject-scoped unique: duplicate subject+entitlement throws.
        $this->expectException(\Throwable::class);
        db($this->context)->table('subscription_overrides')->insert([
            'uuid' => 'ovr00000003', 'tenant_uuid' => 't-1', 'subject_type' => 'user',
            'subject_uuid' => 'u-1', 'entitlement' => 'projects.limit',
            'value' => json_encode(30, JSON_THROW_ON_ERROR),
        ]);
    }

    public function testDownRefusesWhenV2SubjectOrWorkspaceCatalogDataExists(): void
    {
        $this->preparedFixture();
        $schema = $this->connection->getSchemaBuilder();
        (new SubjectModel())->up($schema);

        $sub = db($this->context)->table('subscriptions')->where('tenant_uuid', '=', 't-1')->first();
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's9', 'tenant_uuid' => 't-1', 'subject_type' => 'user', 'subject_uuid' => 'u-1',
            'plan_key' => 'pro', 'plan_uuid' => $sub['plan_uuid'], 'status' => 'active',
        ]);

        $this->expectException(\RuntimeException::class);
        (new SubjectModel())->down($schema);
    }

    public function testDownRefusesWhenWorkspaceOwnedPlanExists(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new SubjectModel())->up($schema);

        db($this->context)->table('subscription_plans')->insert([
            'uuid' => 'planbbbb0001', 'plan_key' => 'member-pro', 'display_name' => 'Member Pro',
            'entitlements' => json_encode(['x' => true], JSON_THROW_ON_ERROR),
            'status' => 'active', 'sort_order' => 0,
            'audience' => 'user', 'owner_tenant_uuid' => 'ws-1',
        ]);

        $this->expectException(\RuntimeException::class);
        (new SubjectModel())->down($schema);
    }

    public function testDownRefusesWhenAReceiptIsNotDerivableFromALegacyEvent(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new SubjectModel())->up($schema);

        // No subscription_events row matches this receipt's claim key at all.
        db($this->context)->table('subscription_provider_event_receipts')->insert([
            'uuid' => 'rcpt0000001', 'provider_gateway' => 'stripe',
            'provider_logical_event_key' => 'subscription.created:sub_orphan:v1',
            'event_type' => 'subscription.created', 'tenant_uuid' => 't-1',
            'subject_type' => 'tenant', 'subject_uuid' => 't-1', 'outcome' => 'accepted',
        ]);

        try {
            (new SubjectModel())->down($schema);
            self::fail('Expected RuntimeException: receipt is not derivable from a legacy event');
        } catch (\RuntimeException $e) {
            // fail closed BEFORE any DDL: the receipts table and v2 columns still exist.
            self::assertTrue($schema->hasTable('subscription_provider_event_receipts'));
            self::assertTrue($schema->hasColumn('subscriptions', 'subject_type'));
            self::assertTrue($schema->hasColumn('subscription_plans', 'audience'));
        }
    }

    public function testDownRefusesWhenAReceiptIsNotAccepted(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        (new SubjectModel())->up($schema);

        // A rejected (or otherwise non-accepted) receipt can never have been produced by
        // 006's own backfill (which only ever writes outcome='accepted'), so it is v2-only
        // audit data -- refuse before any DDL, even if it happens to name a real event.
        db($this->context)->table('subscription_provider_event_receipts')->insert([
            'uuid' => 'rcpt0000002', 'provider_gateway' => 'stripe',
            'provider_logical_event_key' => 'webhook.rejected:sub_rej:v1',
            'event_type' => 'subscription.created', 'tenant_uuid' => 't-1',
            'subject_type' => 'tenant', 'subject_uuid' => 't-1', 'outcome' => 'rejected',
            'rejection_code' => 'mismatched_subject',
        ]);

        try {
            (new SubjectModel())->down($schema);
            self::fail('Expected RuntimeException: receipt outcome is not accepted');
        } catch (\RuntimeException $e) {
            self::assertTrue($schema->hasTable('subscription_provider_event_receipts'));
            self::assertTrue($schema->hasColumn('subscriptions', 'subject_type'));
        }
    }

    public function testAbortIfPlanUuidUnresolvedNamesTheOffendingKeyAndLeavesPlanUuidNullable(): void
    {
        // subscriptions:prepare-v2 structurally cannot leave a dangling plan_key unresolved
        // (it synthesizes an archived plan for every key it finds), so 006's OWN
        // abort-if-unresolved backstop can only be exercised by simulating a corrupted/
        // tampered marker state directly: the guard (exactly one preparation row) passes,
        // but the catalog still doesn't resolve the referenced plan_key.
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's1', 'tenant_uuid' => 't-1', 'plan_key' => 'ghost-plan', 'status' => 'active',
        ]);
        db($this->context)->table('subscription_v2_preparation')->insert([
            'marker_key' => 'subject-model-v2', 'catalog_signature' => 'test',
        ]);

        $schema = $this->connection->getSchemaBuilder();

        try {
            (new SubjectModel())->up($schema);
            self::fail('Expected RuntimeException for unresolved plan_uuid');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('ghost-plan', $e->getMessage());
        }

        // No half-applied constraint: plan_uuid is still nullable -- the constrain step
        // (which makes it NOT NULL) never ran, because the abort happens before it.
        self::assertTrue($schema->hasColumn('subscriptions', 'plan_uuid'));
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's2', 'tenant_uuid' => 't-2', 'plan_key' => 'ghost-plan', 'status' => 'active',
            'plan_uuid' => null,
        ]);
        $row = db($this->context)->table('subscriptions')->where('uuid', '=', 's2')->first();
        self::assertNull($row['plan_uuid']);
    }

    public function testUpIsReRunnableAfterAnAbortedAttempt(): void
    {
        // Same tampered-marker setup as above: up() throws on unresolved plan_uuid AFTER
        // addSubjectColumns() already ran (DDL auto-commits, no surrounding transaction).
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's1', 'tenant_uuid' => 't-1', 'plan_key' => 'ghost-plan', 'status' => 'active',
        ]);
        db($this->context)->table('subscription_v2_preparation')->insert([
            'marker_key' => 'subject-model-v2', 'catalog_signature' => 'test',
        ]);

        $schema = $this->connection->getSchemaBuilder();
        try {
            (new SubjectModel())->up($schema);
            self::fail('Expected the first attempt to abort on an unresolved plan_uuid');
        } catch (\RuntimeException) {
            // expected -- columns are now already added.
        }

        // Fix the catalog (what an operator would actually do) and re-run: must NOT die on
        // "duplicate column" for subject_type/subject_uuid/plan_uuid/audience/owner_tenant_uuid,
        // which a naive re-run would try to re-add.
        db($this->context)->table('subscription_plans')->insert([
            'uuid' => 'planghost001', 'plan_key' => 'ghost-plan', 'display_name' => 'Ghost',
            'entitlements' => json_encode([], JSON_THROW_ON_ERROR), 'status' => 'archived', 'sort_order' => 0,
        ]);

        (new SubjectModel())->up($schema);

        $sub = db($this->context)->table('subscriptions')->where('uuid', '=', 's1')->first();
        self::assertSame('tenant', $sub['subject_type']);
        self::assertSame('planghost001', $sub['plan_uuid']);
        self::assertTrue($schema->hasTable('subscription_provider_event_receipts'));
    }

    public function testDownReversesACompatibleTenantOnlyFixture(): void
    {
        $this->preparedFixture();
        $schema = $this->connection->getSchemaBuilder();
        (new SubjectModel())->up($schema);

        (new SubjectModel())->down($schema);

        self::assertFalse($schema->hasColumn('subscriptions', 'subject_type'));
        self::assertFalse($schema->hasColumn('subscriptions', 'subject_uuid'));
        self::assertFalse($schema->hasColumn('subscriptions', 'plan_uuid'));
        self::assertFalse($schema->hasColumn('subscription_overrides', 'subject_type'));
        self::assertFalse($schema->hasColumn('subscription_overrides', 'subject_uuid'));
        self::assertFalse($schema->hasColumn('subscription_events', 'subject_type'));
        self::assertFalse($schema->hasColumn('subscription_events', 'subject_uuid'));
        self::assertFalse($schema->hasColumn('subscription_plans', 'audience'));
        self::assertFalse($schema->hasColumn('subscription_plans', 'owner_tenant_uuid'));
        self::assertFalse($schema->hasTable('subscription_provider_event_receipts'));

        // The 1.x tenant-scoped unique is restored: a second row for the same tenant throws.
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's99', 'tenant_uuid' => 'tenant-down-1', 'plan_key' => 'pro', 'status' => 'active',
        ]);
        $this->expectException(\Throwable::class);
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's100', 'tenant_uuid' => 'tenant-down-1', 'plan_key' => 'pro', 'status' => 'active',
        ]);
    }

    /**
     * Runs the REAL 1.x-to-1.4 upgrade bridge (PrepareV2Command, Task 2)
     * against a seeded 1.x-shaped dataset: one subscription on tenant t-1
     * using the config-seeded 'pro' plan.
     */
    private function preparedFixture(): void
    {
        db($this->context)->table('subscriptions')->insert([
            'uuid' => 's1', 'tenant_uuid' => 't-1', 'plan_key' => 'pro', 'status' => 'active',
        ]);

        $command = new PrepareV2Command();
        $this->bindCommand($command);
        $exit = (new CommandTester($command))->execute([]);
        self::assertSame(0, $exit, 'preparedFixture(): subscriptions:prepare-v2 must succeed');
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
