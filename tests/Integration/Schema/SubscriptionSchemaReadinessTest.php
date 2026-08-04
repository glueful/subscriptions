<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness;
use Glueful\Extensions\Subscriptions\Tests\Support\SubscriptionsTestCase;
use Psr\Container\ContainerInterface;

/**
 * Task 3 -- the extension-owned schema readiness authority Thallo's
 * EngineGateway calls to distinguish schema_not_ready from ready. Exercised
 * against the fresh shipped 2.x harness (SubscriptionsTestCase), plus
 * representative partial shapes obtained by dropping pieces of it.
 *
 * The legacy 1.x (pre-006) case lives in SubscriptionSchemaReadinessLegacyTest,
 * since it needs LegacySchemaTestCase's pre-subject-model fixture instead.
 */
final class SubscriptionSchemaReadinessTest extends SubscriptionsTestCase
{
    public function testFreshTwoPointXSchemaIsReady(): void
    {
        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertTrue($readiness->isReady());
    }

    public function testEmptyDatabaseIsNotReady(): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        foreach (
            [
                'subscription_provider_event_receipts',
                'subscriptions',
                'subscription_overrides',
                'subscription_events',
                'subscription_plans',
                'subscription_v2_preparation',
            ] as $table
        ) {
            $schema->dropTableIfExists($table);
        }

        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertFalse($readiness->isReady());
    }

    public function testNotReadyWhenReceiptsTableIsMissing(): void
    {
        $this->connection()->getSchemaBuilder()->dropTableIfExists('subscription_provider_event_receipts');

        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertFalse($readiness->isReady());
    }

    public function testNotReadyWhenSubscriptionsPlanUuidColumnIsMissing(): void
    {
        $this->dropColumn('subscriptions', 'plan_uuid');

        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertFalse($readiness->isReady());
    }

    /**
     * Task 10 (design spec §4.1): migration 007's
     * `subscriptions.checkout_origination_uuid` is part of the minimum 2.x runtime
     * shape this class checks -- a database that ran 001-006 but not 007 is a
     * partial/downgraded install, not a legitimate one, and must resolve to NOT
     * ready exactly like a missing subject-model column does.
     */
    public function testNotReadyWhenCheckoutOriginationUuidColumnIsMissing(): void
    {
        $this->connection()->getSchemaBuilder()
            ->dropIndex('subscriptions', 'idx_subscriptions_checkout_origination');
        $this->dropColumn('subscriptions', 'checkout_origination_uuid');

        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertFalse($readiness->isReady());
    }

    public function testNotReadyWhenOverridesSubjectUuidColumnIsMissing(): void
    {
        // subject_uuid is part of uniq_override_subject_entitlement -- SQLite
        // refuses to drop a column an index still covers, so the index goes first.
        $this->connection()->getSchemaBuilder()
            ->dropIndex('subscription_overrides', 'uniq_override_subject_entitlement');
        $this->dropColumn('subscription_overrides', 'subject_uuid');

        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertFalse($readiness->isReady());
    }

    public function testNotReadyWhenEventsSubjectTypeColumnIsMissing(): void
    {
        $this->dropColumn('subscription_events', 'subject_type');

        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertFalse($readiness->isReady());
    }

    public function testNotReadyWhenPlansOwnerTenantUuidColumnIsMissing(): void
    {
        // owner_tenant_uuid is part of uniq_plans_scope_key -- same SQLite
        // constraint as the overrides case above.
        $this->connection()->getSchemaBuilder()->dropIndex('subscription_plans', 'uniq_plans_scope_key');
        $this->dropColumn('subscription_plans', 'owner_tenant_uuid');

        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertFalse($readiness->isReady());
    }

    public function testNotReadyWhenAConsumedReceiptColumnIsMissing(): void
    {
        $this->dropColumn('subscription_provider_event_receipts', 'candidate_subject_uuid');

        $readiness = new SubscriptionSchemaReadiness($this->appContext());

        self::assertFalse($readiness->isReady());
    }

    /**
     * Readiness is a probe, never a fatal: any thrown DB error while consulting
     * the schema builder must resolve to false, not propagate. Modeled with a
     * Connection double whose getSchemaBuilder() throws exactly the way the
     * real Connection documents it can (a lost/broken PDO connection), bound
     * into a standalone ApplicationContext independent of the harness's own
     * (real, healthy) connection.
     */
    public function testIsReadyReturnsFalseWhenTheSchemaProbeThrows(): void
    {
        $brokenConnection = new class ([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]) extends Connection {
            public function getSchemaBuilder(): SchemaBuilderInterface
            {
                throw new \RuntimeException('connection closed');
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer(new class ($brokenConnection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        });

        $readiness = new SubscriptionSchemaReadiness($context);

        self::assertFalse($readiness->isReady());
    }

    /**
     * SQLite (this build: 3.47) supports a real single-statement
     * `ALTER TABLE ... DROP COLUMN`, unlike the framework's fluent
     * TableBuilder path for SQLite (which the 006 migration documents as
     * emitting no real DDL for drop_columns) -- so this drops columns the
     * same way migration 006's own dialect helper does.
     */
    private function dropColumn(string $table, string $column): void
    {
        $this->connection()->getPDO()->exec("ALTER TABLE \"{$table}\" DROP COLUMN \"{$column}\"");
    }
}
