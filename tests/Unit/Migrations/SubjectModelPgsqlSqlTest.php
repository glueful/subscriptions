<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Unit\Migrations;

use Glueful\Extensions\Subscriptions\Database\Migrations\SubjectModel;
use PHPUnit\Framework\TestCase;

/**
 * I1 -- the PostgreSQL down() -> up() round trip.
 *
 * WHY THIS TEST IS SHAPED LIKE THIS. The whole PHPUnit suite runs on the SQLite
 * in-memory harness (see tests/Support/SubscriptionsTestCase.php), and SQLite has
 * no ALTER TABLE ... ADD CONSTRAINT and no notion of a named table constraint
 * distinct from an index at all -- so no SQLite-backed test can prove what the
 * migration emits on pgsql. Standing up a real PostgreSQL instance is CI-only
 * (see tests/Integration/Concurrency/PostgresSavepointTest.php, which skips
 * unless the env-gated PG DSN is present).
 *
 * What IS provable here without a database is the SQL itself: migration 006's
 * pgsql branches build their statements in two pure, static, side-effect-free
 * helpers, and this test asserts the exact strings they produce. That is the
 * whole of the fix -- the bug was never in the control flow, it was in WHICH
 * statement each branch emitted:
 *
 * - down() used to restore the three 1.x uniques through the fluent `unique()`,
 *   which on pgsql compiles to `CREATE UNIQUE INDEX "name" ...`. A later up()
 *   then ran `ALTER TABLE ... DROP CONSTRAINT "name"`, found no CONSTRAINT of
 *   that name, and aborted the migration with the legacy unique still enforced.
 *   down() now emits a real `ADD CONSTRAINT ... UNIQUE (...)`.
 * - up()'s drop is now tolerant of BOTH shapes, so an install carrying the
 *   pre-fix down()'s index artifact still upgrades: `DROP CONSTRAINT IF EXISTS`
 *   followed by `DROP INDEX IF EXISTS`, the second a no-op whenever the first
 *   did the work (dropping a unique constraint drops its backing index with it).
 *
 * The three constraint NAMES asserted below are the ones migrations 001/002/004
 * created; they must match exactly, since that name is the only handle a later
 * up() has on the object.
 */
final class SubjectModelPgsqlSqlTest extends TestCase
{
    /** @param list<mixed> $args */
    private static function call(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod(SubjectModel::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }

    public function testDownRestoresTheSubscriptionsTenantUniqueAsARealConstraint(): void
    {
        self::assertSame(
            'ALTER TABLE "subscriptions" ADD CONSTRAINT "subscriptions_tenant_uuid_unique" '
                . 'UNIQUE ("tenant_uuid")',
            self::call('pgAddUniqueConstraintSql', [
                'subscriptions',
                'subscriptions_tenant_uuid_unique',
                ['tenant_uuid'],
            ])
        );
    }

    public function testDownRestoresTheOverridesCompositeUniqueAsARealConstraint(): void
    {
        self::assertSame(
            'ALTER TABLE "subscription_overrides" ADD CONSTRAINT "uniq_override_tenant_entitlement" '
                . 'UNIQUE ("tenant_uuid", "entitlement")',
            self::call('pgAddUniqueConstraintSql', [
                'subscription_overrides',
                'uniq_override_tenant_entitlement',
                ['tenant_uuid', 'entitlement'],
            ])
        );
    }

    public function testDownRestoresThePlanKeyUniqueAsARealConstraint(): void
    {
        self::assertSame(
            'ALTER TABLE "subscription_plans" ADD CONSTRAINT "subscription_plans_plan_key_unique" '
                . 'UNIQUE ("plan_key")',
            self::call('pgAddUniqueConstraintSql', [
                'subscription_plans',
                'subscription_plans_plan_key_unique',
                ['plan_key'],
            ])
        );
    }

    /**
     * Both statements, in this order: the constraint form first (it also removes
     * the backing index), the index form second so a pre-fix down()'s
     * `CREATE UNIQUE INDEX` artifact is still cleaned up. Both are IF EXISTS, so
     * up() no longer dies on whichever shape is absent.
     */
    public function testUpDropsTheLegacyUniqueTolerantlyInBothShapes(): void
    {
        self::assertSame(
            [
                'ALTER TABLE "subscriptions" DROP CONSTRAINT IF EXISTS "subscriptions_tenant_uuid_unique"',
                'DROP INDEX IF EXISTS "subscriptions_tenant_uuid_unique"',
            ],
            self::call('pgDropLegacyUniqueSql', ['subscriptions', 'subscriptions_tenant_uuid_unique'])
        );
    }

    public function testTheTolerantDropCoversAllThreeLegacyUniquesByName(): void
    {
        $expected = [
            'subscriptions' => 'subscriptions_tenant_uuid_unique',
            'subscription_overrides' => 'uniq_override_tenant_entitlement',
            'subscription_plans' => 'subscription_plans_plan_key_unique',
        ];

        foreach ($expected as $table => $name) {
            self::assertSame(
                [
                    "ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$name}\"",
                    "DROP INDEX IF EXISTS \"{$name}\"",
                ],
                self::call('pgDropLegacyUniqueSql', [$table, $name])
            );
        }
    }
}
