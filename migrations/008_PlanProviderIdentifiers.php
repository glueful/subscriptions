<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Per-gateway checkout-purchasability projection (design spec §4.2, Task 13):
 * a nullable JSON column on `subscription_plans` carrying a closed
 * `{gateway_key: identifier}` map. `PlanPayloadValidator` validates it on
 * every write path (create/update/import-config) -- keys
 * `/^[a-z0-9_-]{1,50}$/`, identifiers non-empty strings <=191.
 *
 * `PlanPurchasability::forGateway()` is the ONE declared authority for
 * checkout purchasability. The pre-existing scalar `provider_price_id`
 * (migration `004`) remains compatibility-only (webhook correlation for
 * pre-existing provider-managed rows) and is NEVER read for purchasability.
 * There is no automatic migration of the scalar into this map on `up()`:
 * every pre-existing row's `provider_identifiers` stays NULL, so no plan
 * becomes purchasable through the new projection until an operator
 * explicitly configures it (release notes document this).
 *
 * Additive-only, same dialect-specific `down()` shape as migrations `006`/
 * `007`: SQLite's fluent `TableBuilder` emits no real DDL for
 * `dropColumns()`, so `down()` issues the dialect-specific pending operation
 * directly on SQLite and uses the fluent `dropColumn()` on MySQL/PostgreSQL.
 */
final class PlanProviderIdentifiers implements MigrationInterface
{
    private const TABLE = 'subscription_plans';
    private const COLUMN = 'provider_identifiers';

    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $schema->alterTable(self::TABLE, function ($table): void {
            $table->json(self::COLUMN)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $this->dropColumn($schema, self::TABLE, self::COLUMN);
    }

    public function getDescription(): string
    {
        return 'Adds subscription_plans.provider_identifiers (design spec §4.2): the '
            . 'per-gateway checkout-purchasability map.';
    }

    /**
     * MySQL/PostgreSQL only via the fluent path. SQLite gets the same documented
     * dialect-specific pending operation as migrations 006/007's dropColumn() helper.
     */
    private function dropColumn(SchemaBuilderInterface $schema, string $table, string $column): void
    {
        if ($this->driver($schema) === 'sqlite') {
            $schema->addPendingOperation("ALTER TABLE \"{$table}\" DROP COLUMN \"{$column}\"");
            $schema->execute();
            return;
        }

        $schema->alterTable($table, function ($t) use ($column): void {
            $t->dropColumn($column);
        });
    }

    private function driver(SchemaBuilderInterface $schema): string
    {
        return $schema->getConnection()->getDriverName();
    }
}
