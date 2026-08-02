<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Repositories;

use Glueful\Extensions\Subscriptions\Repositories\UniqueViolations;
use PHPUnit\Framework\TestCase;

/**
 * Task 6: the cross-driver unique-violation detector extracted out of
 * SubscriptionEventRepository::isUniqueViolation() into a shared static
 * helper (spec §8) so ProviderEventReceiptRepository can reuse the exact
 * same detection logic. Covers the SQLite/MySQL/PostgreSQL exception shapes
 * the original inline helper handled.
 */
final class UniqueViolationsTest extends TestCase
{
    public function testDetectsSqliteStyleSqlstate23000Message(): void
    {
        self::assertTrue(UniqueViolations::isUniqueViolation(
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed')
        ));
    }

    public function testDetectsMysqlStyleSqlstate23000ViaGetCode(): void
    {
        // Real PDO driver errors surface the SQLSTATE through getCode(), independent of
        // whatever the message text says.
        $e = new class ("Duplicate entry '1' for key 'PRIMARY'", 23000) extends \RuntimeException {
        };
        self::assertTrue(UniqueViolations::isUniqueViolation($e));
    }

    public function testDetectsPostgresStyleSqlstate23505Message(): void
    {
        self::assertTrue(UniqueViolations::isUniqueViolation(
            new \RuntimeException('ERROR: duplicate key value violates unique constraint "uniq_x" SQLSTATE[23505]')
        ));
    }

    public function testDetectsViaGetCodeAlone(): void
    {
        $e = new class ('boom', 23505) extends \RuntimeException {
        };
        self::assertTrue(UniqueViolations::isUniqueViolation($e));
    }

    public function testWalksThePreviousExceptionChain(): void
    {
        $wrapped = new \RuntimeException(
            'wrapped',
            0,
            new \PDOException('duplicate key value violates unique constraint')
        );
        self::assertTrue(UniqueViolations::isUniqueViolation($wrapped));
    }

    public function testUnrelatedExceptionIsNotAUniqueViolation(): void
    {
        self::assertFalse(UniqueViolations::isUniqueViolation(new \RuntimeException('connection refused')));
    }

    public function testUnrelatedWrappedChainIsNotAUniqueViolation(): void
    {
        $wrapped = new \RuntimeException('wrapped', 0, new \RuntimeException('timeout'));
        self::assertFalse(UniqueViolations::isUniqueViolation($wrapped));
    }
}
