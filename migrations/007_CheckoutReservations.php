<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Origination-bound checkout reservation seam (design spec §4.1, Task 10):
 * a nullable VARCHAR(12) column carrying the opaque Payvia checkout
 * `origination_uuid` a `status = 'incomplete'` subscription row was reserved
 * against. This is purely LOCAL correlation state -- never provider authority
 * -- read/written by `SubscriptionService::reserveCheckoutFor()`/
 * `releaseCheckoutReservation()`. Every pre-existing row stays NULL, and
 * provider projection never infers an origination for a row that predates
 * this column.
 *
 * Indexed (not unique): multiple historical rows -- across different
 * subjects, or terminal/never-released reservations -- may legitimately
 * share the same diagnostic lookup by origination during operator
 * investigation; uniqueness is not a property this column needs to enforce
 * (the one-live-reservation invariant is enforced by the existing
 * `uniq_subscriptions_subject` constraint plus `reserveCheckoutFor()`'s own
 * replace-guard, not by this column).
 *
 * Additive-only, mirrors migration 006's dialect handling for down(): SQLite
 * has no `ALTER TABLE ... DROP COLUMN` via the framework's fluent
 * `TableBuilder` (its SQLite generator emits no real DDL for drop_columns --
 * see 006's class docblock for the full investigation), so down() issues the
 * dialect-specific pending operation directly on SQLite (which itself has
 * supported a real single-statement `DROP COLUMN` since 3.35.0) and uses the
 * fluent `dropColumn()` on MySQL/PostgreSQL.
 */
final class CheckoutReservations implements MigrationInterface
{
    private const TABLE = 'subscriptions';
    private const COLUMN = 'checkout_origination_uuid';
    private const INDEX = 'idx_subscriptions_checkout_origination';

    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $schema->alterTable(self::TABLE, function ($table): void {
            $table->string(self::COLUMN, 12)->nullable();
            $table->index(self::COLUMN, self::INDEX);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $schema->dropIndex(self::TABLE, self::INDEX);
        $this->dropColumn($schema, self::TABLE, self::COLUMN);
    }

    public function getDescription(): string
    {
        return 'Adds subscriptions.checkout_origination_uuid (design spec §4.1): the '
            . 'origination-bound checkout reservation seam.';
    }

    /**
     * MySQL/PostgreSQL only via the fluent path. SQLite gets the same documented
     * dialect-specific pending operation as migration 006's dropColumns() helper.
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
