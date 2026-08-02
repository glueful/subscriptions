<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Extensions\Subscriptions\Projection\ProviderEventData;

/**
 * Subject-model migration (design spec §2): generalizes the tenant-only 1.x
 * schema to carry an explicit subject triple (tenant_uuid, subject_type,
 * subject_uuid) across subscriptions/overrides/events, scopes the plan
 * catalog by audience/owner, and introduces the provider-event receipts
 * claim/audit table.
 *
 * Additive-then-constrain with in-migration backfill (spec §3): every column
 * is added nullable-or-defaulted first, backfilled, and only THEN
 * constrained -- a partially-upgraded catalog can never be admitted. A
 * populated install must have run the 1.4 upgrade bridge
 * (`subscriptions:prepare-v2`) first; the guard checks that before any DDL.
 *
 * SQLite portability note (investigated, not assumed): SQLite has no
 * `ALTER TABLE ... ALTER/MODIFY COLUMN`, ever -- there is no way to convert
 * `plan_uuid` to NOT NULL in place. Separately, every 1.x `UNIQUE(...)`
 * constraint declared inline at CREATE TABLE time (`$table->unique(...)`,
 * migrations 001/002/004) is registered by SQLite as an anonymous
 * `sqlite_autoindex_*`, never addressable by the name the migration gave it
 * -- confirmed empirically against this build's SQLite (3.47):
 * `CREATE TABLE t (x TEXT, CONSTRAINT my_name UNIQUE(x))` still surfaces as
 * `sqlite_autoindex_t_1` in `PRAGMA index_list`. `DROP INDEX
 * "subscriptions_tenant_uuid_unique"` therefore cannot find anything to
 * drop, and even `DROP INDEX IF EXISTS` would silently leave the 1.x
 * constraint enforced. The framework's fluent alter API cannot cover this
 * gap either way: `SchemaBuilder::alterTable()` always builds a
 * `TableBuilder`, and `TableBuilder::modifyColumn()` files its result into
 * `add_columns` (not `modify_columns`), so calling it would emit a second
 * `ADD COLUMN` for a column that already exists -- `AlterTableBuilder`,
 * which implements `modifyColumn()` correctly, is unreachable dead code,
 * never constructed anywhere in the framework outside its own file.
 *
 * SQLite therefore gets a documented, minimal rebuild of exactly the three
 * affected tables (rename data aside, recreate with the final column set,
 * copy, drop, rename into place), after which every unique/index this
 * migration cares about is (re)created as a real STANDALONE named index
 * (`CREATE UNIQUE INDEX`, not an inline table constraint) -- which SQLite
 * *does* register under the given name and *can* drop by name. That is also
 * what makes `down()` symmetric on SQLite. `subscription_events` never
 * changes its indexes and needs no rebuild. MySQL and PostgreSQL never hit
 * this gap: both name inline unique constraints faithfully, and both
 * support a real `ALTER TABLE ... MODIFY/ALTER COLUMN`, applied directly via
 * a documented dialect-specific pending operation (the fluent API's
 * `modifyColumn()` bug above rules out the fluent path there too).
 */
final class SubjectModel implements MigrationInterface
{
    private const RECEIPTS_TABLE = 'subscription_provider_event_receipts';

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->guardPreparedUpgrade($schema);

        $this->addSubjectColumns($schema);
        $this->backfillSubjectUuids($schema);
        $this->backfillPlanUuid($schema);
        $this->abortIfPlanUuidUnresolved($schema);

        $this->constrainSubscriptions($schema);
        $this->constrainOverrides($schema);
        $this->constrainPlans($schema);

        $this->createReceiptsTable($schema);
        $this->backfillReceipts($schema);
        $this->sanitizeHistoricalProviderEventData($schema);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->guardReversibleState($schema);

        $schema->dropTableIfExists(self::RECEIPTS_TABLE);

        $this->reverseSubscriptions($schema);
        $this->reverseOverrides($schema);
        $this->reverseEvents($schema);
        $this->reversePlans($schema);
    }

    public function getDescription(): string
    {
        return 'Subject-model: tenant/user subject triple, scoped plan catalog, '
            . 'provider-event receipts (spec §2).';
    }

    // ===========================================
    // up() steps
    // ===========================================

    /**
     * Spec §3: a populated install requires exactly one preparation-state row
     * (written by the final-1.x `subscriptions:prepare-v2` command) before
     * any schema change. A fresh install (zero subscription rows) is exempt.
     */
    private function guardPreparedUpgrade(SchemaBuilderInterface $schema): void
    {
        $hasLegacyRows = $schema->hasTable('subscriptions') && $schema->getTableRowCount('subscriptions') > 0;
        if (!$hasLegacyRows) {
            return;
        }

        $prepared = $schema->hasTable('subscription_v2_preparation')
            ? $schema->getTableRowCount('subscription_v2_preparation')
            : 0;

        if ($prepared !== 1) {
            throw new \RuntimeException(
                'subscriptions v2 requires the 1.4 upgrade bridge: install the final 1.x release, '
                . 'run `subscriptions:prepare-v2`, then migrate. '
                . "(preparation rows found: {$prepared}, expected exactly 1)"
            );
        }
    }

    /**
     * Guards every column add with hasColumn() (mirroring createReceiptsTable()'s
     * analogous hasTable() guard) so up() is idempotent/re-runnable: DDL auto-commits
     * (MigrationManager runs up() with no transaction), so a mid-flight abort --
     * abortIfPlanUuidUnresolved(), or an unrelated failure during constrain -- must not
     * leave a re-run dying on "duplicate column" for columns a prior attempt already added.
     */
    private function addSubjectColumns(SchemaBuilderInterface $schema): void
    {
        $this->ensureColumn($schema, 'subscriptions', 'subject_type', function ($table): void {
            $table->string('subject_type', 10)->notNull()->default('tenant');
        });
        $this->ensureColumn($schema, 'subscriptions', 'subject_uuid', function ($table): void {
            $table->string('subject_uuid', 64)->notNull()->default('');
        });
        $this->ensureColumn($schema, 'subscriptions', 'plan_uuid', function ($table): void {
            $table->string('plan_uuid', 12)->nullable();
        });

        $this->ensureColumn($schema, 'subscription_overrides', 'subject_type', function ($table): void {
            $table->string('subject_type', 10)->notNull()->default('tenant');
        });
        $this->ensureColumn($schema, 'subscription_overrides', 'subject_uuid', function ($table): void {
            $table->string('subject_uuid', 64)->notNull()->default('');
        });

        $this->ensureColumn($schema, 'subscription_events', 'subject_type', function ($table): void {
            $table->string('subject_type', 10)->notNull()->default('tenant');
        });
        $this->ensureColumn($schema, 'subscription_events', 'subject_uuid', function ($table): void {
            $table->string('subject_uuid', 64)->notNull()->default('');
        });

        $this->ensureColumn($schema, 'subscription_plans', 'audience', function ($table): void {
            $table->string('audience', 10)->notNull()->default('tenant');
        });
        $this->ensureColumn($schema, 'subscription_plans', 'owner_tenant_uuid', function ($table): void {
            $table->string('owner_tenant_uuid', 64)->notNull()->default('');
        });
    }

    private function ensureColumn(
        SchemaBuilderInterface $schema,
        string $table,
        string $column,
        callable $define
    ): void {
        if ($schema->hasColumn($table, $column)) {
            return;
        }

        $schema->alterTable($table, $define);
    }

    private function backfillSubjectUuids(SchemaBuilderInterface $schema): void
    {
        $pdo = $schema->getConnection()->getPDO();
        foreach (['subscriptions', 'subscription_overrides', 'subscription_events'] as $table) {
            $pdo->exec("UPDATE {$table} SET subject_uuid = tenant_uuid WHERE subject_uuid = ''");
        }
    }

    private function backfillPlanUuid(SchemaBuilderInterface $schema): void
    {
        $schema->getConnection()->getPDO()->exec(
            'UPDATE subscriptions SET plan_uuid = ('
            . 'SELECT uuid FROM subscription_plans p WHERE p.plan_key = subscriptions.plan_key'
            . ') WHERE plan_uuid IS NULL'
        );
    }

    /**
     * Abort-if-unresolved (spec §3): a partially-upgraded catalog is never
     * admitted. Names the offending plan_key(s) via a second query.
     */
    private function abortIfPlanUuidUnresolved(SchemaBuilderInterface $schema): void
    {
        $pdo = $schema->getConnection()->getPDO();
        $unresolvedStmt = $pdo->query('SELECT COUNT(*) FROM subscriptions WHERE plan_uuid IS NULL');
        $unresolved = (int) $unresolvedStmt->fetchColumn();

        if ($unresolved === 0) {
            return;
        }

        $keysStmt = $pdo->query('SELECT DISTINCT plan_key FROM subscriptions WHERE plan_uuid IS NULL');
        $keys = $keysStmt->fetchAll(\PDO::FETCH_COLUMN);

        throw new \RuntimeException(
            "subscriptions v2 migration cannot resolve plan_uuid for {$unresolved} row(s) referencing "
            . 'plan_key(s): ' . implode(', ', array_map('strval', $keys)) . '. '
            . 'Run `subscriptions:prepare-v2` against the final 1.x release before migrating.'
        );
    }

    private function constrainSubscriptions(SchemaBuilderInterface $schema): void
    {
        if ($this->driver($schema) === 'sqlite') {
            $this->sqliteRebuildSubscriptions($schema);
            return;
        }

        $this->alterColumnNotNull($schema, 'subscriptions', 'plan_uuid', $this->varchar(12));
        $this->dropLegacyUniqueConstraint($schema, 'subscriptions', 'subscriptions_tenant_uuid_unique');
        $schema->alterTable('subscriptions', function ($table): void {
            $table->unique(['tenant_uuid', 'subject_type', 'subject_uuid'], 'uniq_subscriptions_subject');
        });
    }

    private function constrainOverrides(SchemaBuilderInterface $schema): void
    {
        if ($this->driver($schema) === 'sqlite') {
            $this->sqliteRebuildOverrides($schema);
            return;
        }

        $this->dropLegacyUniqueConstraint($schema, 'subscription_overrides', 'uniq_override_tenant_entitlement');
        $schema->alterTable('subscription_overrides', function ($table): void {
            $table->unique(
                ['tenant_uuid', 'subject_type', 'subject_uuid', 'entitlement'],
                'uniq_override_subject_entitlement'
            );
        });
    }

    private function constrainPlans(SchemaBuilderInterface $schema): void
    {
        if ($this->driver($schema) === 'sqlite') {
            $this->sqliteRebuildPlans($schema);
            return;
        }

        $this->dropLegacyUniqueConstraint($schema, 'subscription_plans', 'subscription_plans_plan_key_unique');
        $schema->alterTable('subscription_plans', function ($table): void {
            $table->unique(['audience', 'owner_tenant_uuid', 'plan_key'], 'uniq_plans_scope_key');
        });
    }

    private function createReceiptsTable(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable(self::RECEIPTS_TABLE)) {
            return;
        }

        $schema->createTable(self::RECEIPTS_TABLE, function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12)->notNull();
            $table->string('provider_gateway', 50)->notNull();
            $table->string('provider_logical_event_key', 191)->nullable();
            $table->string('event_type', 40)->notNull();
            $table->string('candidate_tenant_uuid', 64)->nullable();
            $table->string('candidate_subject_type', 10)->nullable();
            $table->string('candidate_subject_uuid', 64)->nullable();
            $table->string('candidate_plan_uuid', 12)->nullable();
            $table->string('tenant_uuid', 64)->nullable();
            $table->string('subject_type', 10)->nullable();
            $table->string('subject_uuid', 64)->nullable();
            $table->string('plan_uuid', 12)->nullable();
            $table->string('outcome', 20)->notNull();
            $table->string('rejection_code', 60)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->unique(
                ['provider_gateway', 'provider_logical_event_key'],
                'uniq_receipts_gateway_logical_key'
            );
        });
    }

    /**
     * Spec §2: every existing provider lifecycle event with a non-null
     * gateway AND logical key is copied into an accepted receipt after
     * event-subject backfill, reusing the event uuid. Manual/non-provider
     * events (source='manual', gateway/key null) are never copied.
     */
    private function backfillReceipts(SchemaBuilderInterface $schema): void
    {
        $schema->getConnection()->getPDO()->exec(
            'INSERT INTO ' . self::RECEIPTS_TABLE . ' '
            . '(uuid, provider_gateway, provider_logical_event_key, event_type, '
            . 'tenant_uuid, subject_type, subject_uuid, outcome, created_at) '
            . 'SELECT uuid, provider_gateway, provider_logical_event_key, type, '
            . "tenant_uuid, subject_type, subject_uuid, 'accepted', created_at "
            . 'FROM subscription_events '
            . 'WHERE provider_gateway IS NOT NULL AND provider_logical_event_key IS NOT NULL'
        );
    }

    /**
     * Spec ruling (Task 10 fix round 2): the upgrade itself must not leave
     * historical PII/secrets sitting in the database. Every PROVIDER-sourced
     * `subscription_events` row (non-null gateway AND logical key -- the same
     * predicate backfillReceipts() uses) may carry a raw provider payload in
     * `data` written before ProviderEventData::sanitize() existed. Each such
     * row's `data` is re-projected through sanitize() and written back in
     * place. Manual/reconcile events (gateway/key null) are untouched -- their
     * data is app-generated, never a raw provider payload.
     *
     * PHP-side (not portable SQL) because the sanitization logic itself lives
     * in application code, not the database. Naturally idempotent: sanitizing
     * an already-safe payload is a no-op, so a re-run (or a second migration
     * attempt after an unrelated abort) converges to the same result rather
     * than double-transforming anything.
     */
    private function sanitizeHistoricalProviderEventData(SchemaBuilderInterface $schema): void
    {
        $pdo = $schema->getConnection()->getPDO();

        $rows = $pdo->query(
            'SELECT uuid, data FROM subscription_events '
            . 'WHERE provider_gateway IS NOT NULL AND provider_logical_event_key IS NOT NULL '
            . 'AND data IS NOT NULL'
        )->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return;
        }

        $update = $pdo->prepare('UPDATE subscription_events SET data = :data WHERE uuid = :uuid');

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['data'], true);
            if (!is_array($decoded)) {
                continue; // unparseable/non-object data -- nothing safe to reconstruct, leave as-is
            }

            $update->execute([
                'data' => json_encode(ProviderEventData::sanitize($decoded), JSON_THROW_ON_ERROR),
                'uuid' => $row['uuid'],
            ]);
        }
    }

    // ===========================================
    // down() steps
    // ===========================================

    /**
     * down() is reversible only while the database remains representable by
     * 1.x. Fails closed -- before any DDL -- when any subject_type<>'tenant'
     * row exists, any audience='user'/non-platform plan exists, or any
     * receipt cannot be derived solely from a legacy event.
     */
    private function guardReversibleState(SchemaBuilderInterface $schema): void
    {
        $pdo = $schema->getConnection()->getPDO();

        // A subject_type='tenant' row whose subject_uuid diverges from tenant_uuid is
        // ALSO not representable by 1.x (spec §1: subject_type=tenant => subject_uuid ===
        // tenant_uuid) even though it passes a subject_type-only check -- restoring
        // UNIQUE(tenant_uuid) afterward would then fail deep inside DDL (or worse, silently
        // admit incoherent 1.x data), after receipts/columns were already dropped. Caught
        // here, before any DDL.
        $this->assertNoRows(
            $pdo,
            $schema,
            'subscriptions',
            "SELECT COUNT(*) FROM subscriptions WHERE subject_type <> 'tenant' OR subject_uuid <> tenant_uuid",
            'subscriptions'
        );
        $this->assertNoRows(
            $pdo,
            $schema,
            'subscription_overrides',
            "SELECT COUNT(*) FROM subscription_overrides "
            . "WHERE subject_type <> 'tenant' OR subject_uuid <> tenant_uuid",
            'subscription_overrides'
        );
        $this->assertNoRows(
            $pdo,
            $schema,
            'subscription_events',
            "SELECT COUNT(*) FROM subscription_events WHERE subject_type <> 'tenant' OR subject_uuid <> tenant_uuid",
            'subscription_events'
        );
        $this->assertNoRows(
            $pdo,
            $schema,
            'subscription_plans',
            "SELECT COUNT(*) FROM subscription_plans WHERE audience <> 'tenant' OR owner_tenant_uuid <> ''",
            'subscription_plans (workspace-owned membership plans)'
        );

        if (!$schema->hasTable(self::RECEIPTS_TABLE)) {
            return;
        }

        $underiveableStmt = $pdo->query(
            'SELECT COUNT(*) FROM ' . self::RECEIPTS_TABLE . ' r '
            . "WHERE r.outcome <> 'accepted' OR NOT EXISTS ("
            . 'SELECT 1 FROM subscription_events e WHERE e.uuid = r.uuid '
            . 'AND e.provider_gateway = r.provider_gateway '
            . 'AND e.provider_logical_event_key = r.provider_logical_event_key '
            . 'AND e.type = r.event_type AND e.tenant_uuid = r.tenant_uuid '
            . 'AND e.subject_type = r.subject_type AND e.subject_uuid = r.subject_uuid'
            . ')'
        );
        $underiveable = (int) $underiveableStmt->fetchColumn();

        if ($underiveable > 0) {
            throw new \RuntimeException(
                'Cannot reverse the subject-model migration: provider-event receipts exist that are not '
                . 'derivable from a legacy subscription event. Restore a pre-2.0 backup instead of rolling '
                . 'back this migration.'
            );
        }
    }

    private function assertNoRows(
        \PDO $pdo,
        SchemaBuilderInterface $schema,
        string $table,
        string $sql,
        string $label
    ): void {
        if (!$schema->hasTable($table)) {
            return;
        }

        $count = (int) $pdo->query($sql)->fetchColumn();
        if ($count > 0) {
            throw new \RuntimeException(
                "Cannot reverse the subject-model migration: {$label} contains v2-only data. "
                . 'Restore a pre-2.0 backup instead of rolling back this migration.'
            );
        }
    }

    private function reverseSubscriptions(SchemaBuilderInterface $schema): void
    {
        $schema->alterTable('subscriptions', function ($table): void {
            $table->dropIndex('uniq_subscriptions_subject');
        });
        $this->dropColumns($schema, 'subscriptions', ['plan_uuid', 'subject_uuid', 'subject_type']);
        $schema->alterTable('subscriptions', function ($table): void {
            $table->unique('tenant_uuid', 'subscriptions_tenant_uuid_unique');
        });
    }

    private function reverseOverrides(SchemaBuilderInterface $schema): void
    {
        $schema->alterTable('subscription_overrides', function ($table): void {
            $table->dropIndex('uniq_override_subject_entitlement');
        });
        $this->dropColumns($schema, 'subscription_overrides', ['subject_uuid', 'subject_type']);
        $schema->alterTable('subscription_overrides', function ($table): void {
            $table->unique(['tenant_uuid', 'entitlement'], 'uniq_override_tenant_entitlement');
        });
    }

    private function reverseEvents(SchemaBuilderInterface $schema): void
    {
        $this->dropColumns($schema, 'subscription_events', ['subject_uuid', 'subject_type']);
    }

    private function reversePlans(SchemaBuilderInterface $schema): void
    {
        $schema->alterTable('subscription_plans', function ($table): void {
            $table->dropIndex('uniq_plans_scope_key');
        });
        $this->dropColumns($schema, 'subscription_plans', ['owner_tenant_uuid', 'audience']);
        $schema->alterTable('subscription_plans', function ($table): void {
            $table->unique('plan_key', 'subscription_plans_plan_key_unique');
        });
    }

    // ===========================================
    // Dialect helpers
    // ===========================================

    private function driver(SchemaBuilderInterface $schema): string
    {
        return $schema->getConnection()->getDriverName();
    }

    private function varchar(int $length): string
    {
        return "VARCHAR({$length})";
    }

    /**
     * MySQL/PostgreSQL only (SQLite has no ALTER/MODIFY COLUMN at all -- see
     * class docblock). Issued as a documented dialect-specific pending
     * operation because the fluent alter API cannot express a column
     * modification (`TableBuilder::modifyColumn()` misfiles into
     * `add_columns`; the correct `AlterTableBuilder::modifyColumn()` is
     * unreachable dead code).
     */
    private function alterColumnNotNull(
        SchemaBuilderInterface $schema,
        string $table,
        string $column,
        string $sqlType
    ): void {
        $driver = $this->driver($schema);
        $sql = match ($driver) {
            'mysql' => "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$sqlType} NOT NULL",
            'pgsql' => "ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" SET NOT NULL",
            default => throw new \RuntimeException("Unsupported driver for column alteration: {$driver}"),
        };
        $schema->addPendingOperation($sql);
        $schema->execute();
    }

    /**
     * MySQL/PostgreSQL only. Drops one of the three 1.x create-time unique constraints
     * (migrations 001/002/004). MySQL's inline `UNIQUE KEY` is a real index, droppable via
     * the fluent `dropIndex()`/`DROP INDEX`. PostgreSQL is different:
     * `PostgreSQLSqlGenerator::createTable()` emits inline uniques as a named TABLE
     * CONSTRAINT (`CONSTRAINT "name" UNIQUE (...)`, not a standalone index), and Postgres
     * refuses `DROP INDEX` against a constraint-backed index ("use ALTER TABLE ... DROP
     * CONSTRAINT instead" -- a real, verified PG error, not a hypothetical). Issued as a
     * documented dialect-specific pending operation for pgsql; MySQL keeps the fluent path.
     */
    private function dropLegacyUniqueConstraint(
        SchemaBuilderInterface $schema,
        string $table,
        string $constraintName
    ): void {
        if ($this->driver($schema) === 'pgsql') {
            $schema->addPendingOperation("ALTER TABLE \"{$table}\" DROP CONSTRAINT \"{$constraintName}\"");
            $schema->execute();
            return;
        }

        $schema->alterTable($table, function ($t) use ($constraintName): void {
            $t->dropIndex($constraintName);
        });
    }

    /**
     * SQLite drop-column fallback: the framework's SQLite generator refuses
     * to emit real DDL for drop_columns (a static "recreate the table"
     * comment), even though SQLite itself has supported a real, single
     * statement `ALTER TABLE ... DROP COLUMN` since 3.35.0 (this build:
     * 3.47). Issued as a documented dialect-specific pending operation.
     * @param list<string> $columns
     */
    private function dropColumns(SchemaBuilderInterface $schema, string $table, array $columns): void
    {
        if ($this->driver($schema) === 'sqlite') {
            foreach ($columns as $column) {
                $schema->addPendingOperation("ALTER TABLE \"{$table}\" DROP COLUMN \"{$column}\"");
            }
            $schema->execute();
            return;
        }

        $schema->alterTable($table, function ($t) use ($columns): void {
            foreach ($columns as $column) {
                $t->dropColumn($column);
            }
        });
    }

    // ===========================================
    // SQLite table rebuilds (see class docblock)
    // ===========================================

    private function sqliteRebuildSubscriptions(SchemaBuilderInterface $schema): void
    {
        $tmp = 'subscriptions__006_rebuild';
        $schema->addPendingOperation("DROP TABLE IF EXISTS \"{$tmp}\"");
        // Every pre-existing column here is nullable at the DB level in 1.x (confirmed via
        // PRAGMA table_info against migrations 001-004: ColumnBuilder defaults to
        // nullable=true unless a column explicitly calls notNull()/primary(), and none of
        // uuid/tenant_uuid/plan_key/status/... do). Only the three columns this migration
        // itself constrains -- subject_type, subject_uuid, plan_uuid -- get NOT NULL here;
        // tightening any 1.x column beyond its original nullability would be an
        // undocumented, SQLite-only behavior change relative to MySQL/PostgreSQL.
        $schema->addPendingOperation(
            "CREATE TABLE \"{$tmp}\" ("
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"tenant_uuid" TEXT, '
            . '"plan_key" TEXT, '
            . "\"status\" TEXT DEFAULT 'active', "
            . '"trial_ends_at" TEXT, '
            . '"current_period_end" TEXT, '
            . '"grace_ends_at" TEXT, '
            . '"canceled_at" TEXT, '
            . '"provider_gateway" TEXT, '
            . '"provider_customer_id" TEXT, '
            . '"provider_subscription_id" TEXT, '
            . '"provider_price_id" TEXT, '
            . '"metadata" TEXT, '
            . '"created_at" TEXT DEFAULT CURRENT_TIMESTAMP, '
            . '"updated_at" TEXT, '
            . "\"subject_type\" TEXT NOT NULL DEFAULT 'tenant', "
            . "\"subject_uuid\" TEXT NOT NULL DEFAULT '', "
            . '"plan_uuid" TEXT NOT NULL'
            . ')'
        );
        $schema->execute();

        $columns = 'id, uuid, tenant_uuid, plan_key, status, trial_ends_at, current_period_end, '
            . 'grace_ends_at, canceled_at, provider_gateway, provider_customer_id, provider_subscription_id, '
            . 'provider_price_id, metadata, created_at, updated_at, subject_type, subject_uuid, plan_uuid';
        $schema->getConnection()->getPDO()->exec(
            "INSERT INTO \"{$tmp}\" ({$columns}) SELECT {$columns} FROM subscriptions"
        );

        $schema->addPendingOperation('DROP TABLE subscriptions');
        $schema->addPendingOperation("ALTER TABLE \"{$tmp}\" RENAME TO subscriptions");
        $schema->execute();

        $schema->alterTable('subscriptions', function ($table): void {
            $table->unique('uuid', 'subscriptions_uuid_unique');
            $table->unique(['provider_gateway', 'provider_subscription_id'], 'uniq_subscriptions_provider_sub');
            $table->unique(['tenant_uuid', 'subject_type', 'subject_uuid'], 'uniq_subscriptions_subject');
        });
    }

    private function sqliteRebuildOverrides(SchemaBuilderInterface $schema): void
    {
        $tmp = 'subscription_overrides__006_rebuild';
        $schema->addPendingOperation("DROP TABLE IF EXISTS \"{$tmp}\"");
        // See the analogous comment in sqliteRebuildSubscriptions(): every pre-existing
        // column here is nullable in 1.x (migration 002); only subject_type/subject_uuid
        // (added by this migration) get NOT NULL.
        $schema->addPendingOperation(
            "CREATE TABLE \"{$tmp}\" ("
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"tenant_uuid" TEXT, '
            . '"entitlement" TEXT, '
            . '"value" TEXT, '
            . '"expires_at" TEXT, '
            . '"reason" TEXT, '
            . '"created_at" TEXT DEFAULT CURRENT_TIMESTAMP, '
            . '"updated_at" TEXT, '
            . "\"subject_type\" TEXT NOT NULL DEFAULT 'tenant', "
            . "\"subject_uuid\" TEXT NOT NULL DEFAULT ''"
            . ')'
        );
        $schema->execute();

        $columns = 'id, uuid, tenant_uuid, entitlement, value, expires_at, reason, created_at, updated_at, '
            . 'subject_type, subject_uuid';
        $schema->getConnection()->getPDO()->exec(
            "INSERT INTO \"{$tmp}\" ({$columns}) SELECT {$columns} FROM subscription_overrides"
        );

        $schema->addPendingOperation('DROP TABLE subscription_overrides');
        $schema->addPendingOperation("ALTER TABLE \"{$tmp}\" RENAME TO subscription_overrides");
        $schema->execute();

        $schema->alterTable('subscription_overrides', function ($table): void {
            $table->unique('uuid', 'subscription_overrides_uuid_unique');
            $table->index('tenant_uuid', 'idx_overrides_tenant');
            $table->unique(
                ['tenant_uuid', 'subject_type', 'subject_uuid', 'entitlement'],
                'uniq_override_subject_entitlement'
            );
        });
    }

    private function sqliteRebuildPlans(SchemaBuilderInterface $schema): void
    {
        $tmp = 'subscription_plans__006_rebuild';
        $schema->addPendingOperation("DROP TABLE IF EXISTS \"{$tmp}\"");
        // See the analogous comment in sqliteRebuildSubscriptions(): every pre-existing
        // column here is nullable in 1.x (migration 004); only audience/owner_tenant_uuid
        // (added by this migration) get NOT NULL.
        $schema->addPendingOperation(
            "CREATE TABLE \"{$tmp}\" ("
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"plan_key" TEXT, '
            . '"display_name" TEXT, '
            . '"description" TEXT, '
            . '"entitlements" TEXT, '
            . '"provider_price_id" TEXT, '
            . '"status" TEXT, '
            . '"sort_order" INTEGER DEFAULT 0, '
            . '"created_at" TEXT DEFAULT CURRENT_TIMESTAMP, '
            . '"updated_at" TEXT, '
            . "\"audience\" TEXT NOT NULL DEFAULT 'tenant', "
            . "\"owner_tenant_uuid\" TEXT NOT NULL DEFAULT ''"
            . ')'
        );
        $schema->execute();

        $columns = 'id, uuid, plan_key, display_name, description, entitlements, provider_price_id, status, '
            . 'sort_order, created_at, updated_at, audience, owner_tenant_uuid';
        $schema->getConnection()->getPDO()->exec(
            "INSERT INTO \"{$tmp}\" ({$columns}) SELECT {$columns} FROM subscription_plans"
        );

        $schema->addPendingOperation('DROP TABLE subscription_plans');
        $schema->addPendingOperation("ALTER TABLE \"{$tmp}\" RENAME TO subscription_plans");
        $schema->execute();

        $schema->alterTable('subscription_plans', function ($table): void {
            $table->unique('uuid', 'subscription_plans_uuid_unique');
            $table->index('status', 'idx_subscription_plans_status');
            $table->index('updated_at', 'idx_subscription_plans_updated_at');
            $table->unique(['audience', 'owner_tenant_uuid', 'plan_key'], 'uniq_plans_scope_key');
        });
    }
}
