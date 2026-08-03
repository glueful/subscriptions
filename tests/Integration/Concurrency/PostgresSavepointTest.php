<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Concurrency;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Subscriptions\Catalog\PlanCatalog;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionEventsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionOverridesTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionPlansTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateV2PreparationState;
use Glueful\Extensions\Subscriptions\Database\Migrations\SubjectModel;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionEventRepository;
use Glueful\Extensions\Subscriptions\Repositories\SubscriptionRepository;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubscriptionConflictException;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Helpers\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Task 15 -- the real-PostgreSQL proof behind `SubscriptionService::startFor()`'s
 * nested-transaction savepoint (spec §8).
 *
 * SQLite tolerates a unique-constraint violation inside an already-open
 * transaction without poisoning the session, so
 * `SubscriptionServiceSubjectTest::testTheSavepointLeavesTheOuterTransactionUsable`
 * cannot actually exercise the failure mode the savepoint exists to prevent. On
 * real PostgreSQL, an unhandled error inside a transaction aborts EVERY
 * subsequent statement ("current transaction is aborted, commands ignored
 * until end of transaction block") until a ROLLBACK -- or, with a SAVEPOINT, a
 * ROLLBACK TO SAVEPOINT that only unwinds back to that point. Only a genuine
 * PostgreSQL backend can prove the savepoint keeps the surrounding transaction
 * usable afterwards; that is the entire point of this suite.
 *
 * Gated on three env vars (see README "Testing against PostgreSQL") so it is a
 * silent no-op everywhere a real PostgreSQL server isn't reachable:
 *   - SUBSCRIPTIONS_TEST_PG_DSN   e.g. "pgsql:host=127.0.0.1;port=5432;dbname=subscriptions_test"
 *   - SUBSCRIPTIONS_TEST_PG_USER
 *   - SUBSCRIPTIONS_TEST_PG_PASS  (may be an empty string for trust-auth setups)
 */
final class PostgresSavepointTest extends TestCase
{
    private const PLATFORM_FREE = 'planv2free01';
    private const PLATFORM_PRO = 'planv2pro001';

    private ApplicationContext $context;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('SUBSCRIPTIONS_TEST_PG_DSN');
        $user = getenv('SUBSCRIPTIONS_TEST_PG_USER');
        $pass = getenv('SUBSCRIPTIONS_TEST_PG_PASS');

        if ($dsn === false || $dsn === '' || $user === false || $pass === false) {
            self::markTestSkipped(
                'Set SUBSCRIPTIONS_TEST_PG_DSN (+ _USER/_PASS) to run the PostgreSQL savepoint '
                . 'isolation proof against a real server -- see README "Testing against PostgreSQL".'
            );
        }

        $this->connection = new Connection([
            'engine' => 'pgsql',
            'pgsql' => array_merge(self::parseDsn($dsn), [
                'user' => $user,
                'pass' => $pass,
            ]),
            'pooling' => ['enabled' => false],
        ]);

        $this->resetSchema();
        $this->applyMigrations();

        $connection = $this->connection;
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private readonly Connection $connection)
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
        };

        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $this->context->setContainer($container);
        $this->context->mergeConfigDefaults(
            'subscriptions',
            require __DIR__ . '/../../../config/subscriptions.php'
        );

        $this->seedPlatformPlans();
    }

    /**
     * Parses the minimal `key=value;key=value` shape a PDO pgsql DSN uses (an
     * optional leading `pgsql:` is stripped) into the host/port/db keys the
     * framework `Connection`'s pgsql config array expects -- see
     * vendor/glueful/framework/src/Database/Connection.php `buildDSN()`.
     *
     * @return array{host:string,port:int,db:string}
     */
    private static function parseDsn(string $dsn): array
    {
        $dsn = preg_replace('/^pgsql:/', '', $dsn) ?? $dsn;

        $parts = [];
        foreach (explode(';', $dsn) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        return [
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => isset($parts['port']) ? (int) $parts['port'] : 5432,
            'db' => $parts['dbname'] ?? $parts['db'] ?? '',
        ];
    }

    /**
     * A persistent local PostgreSQL (unlike CI's fresh-per-run service
     * container) carries this suite's tables across runs -- drop them first so
     * every run starts from the same clean slate migrations 001-006 expect.
     */
    private function resetSchema(): void
    {
        $pdo = $this->connection->getPDO();
        foreach (
            [
                'subscription_provider_event_receipts',
                'subscription_events',
                'subscription_overrides',
                'subscriptions',
                'subscription_plans',
                'subscription_v2_preparation',
            ] as $table
        ) {
            $pdo->exec("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
        }
    }

    private function applyMigrations(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        (new CreateSubscriptionsTable())->up($schema);
        (new CreateSubscriptionOverridesTable())->up($schema);
        (new CreateSubscriptionEventsTable())->up($schema);
        (new CreateSubscriptionPlansTable())->up($schema);
        (new CreateV2PreparationState())->up($schema);
        (new SubjectModel())->up($schema);
    }

    /**
     * Mirrors SubscriptionsTestCase::seedPlatformPlans() -- the DB-authoritative
     * PlanCatalog requires real 'free'/'pro' rows in the ('tenant', '') scope.
     */
    private function seedPlatformPlans(): void
    {
        $configPlans = (array) config($this->context, 'subscriptions.plans', []);
        $sortOrder = 0;

        foreach (['free', 'pro'] as $planKey) {
            $plan = is_array($configPlans[$planKey] ?? null) ? $configPlans[$planKey] : [];

            $this->connection->table('subscription_plans')->insert([
                'uuid' => $planKey === 'free' ? self::PLATFORM_FREE : self::PLATFORM_PRO,
                'plan_key' => $planKey,
                'display_name' => ucfirst($planKey),
                'description' => null,
                'entitlements' => json_encode(
                    is_array($plan['entitlements'] ?? null) ? $plan['entitlements'] : [],
                    JSON_THROW_ON_ERROR
                ),
                'provider_price_id' => $plan['provider_price_id'] ?? null,
                'status' => 'active',
                'sort_order' => $sortOrder++,
                'audience' => 'tenant',
                'owner_tenant_uuid' => '',
            ]);
        }
    }

    private function service(): SubscriptionService
    {
        return new SubscriptionService(
            new SubscriptionRepository(),
            new SubscriptionEventRepository(),
            PlanCatalog::fromContext($this->context),
            $this->context,
            new DefaultSubjectResolver(),
        );
    }

    /**
     * Inserts a subscription row directly (bypassing the service) so a
     * subsequent startFor() call for the same subject loses the
     * `uniq_subscriptions_subject` unique constraint.
     *
     * @param array<string,mixed> $overrides
     */
    private function seedSubscriptionRow(array $overrides = []): string
    {
        $uuid = (string) ($overrides['uuid'] ?? Utils::generateNanoID(12));

        $this->connection->table('subscriptions')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => 'tenantA',
            'subject_type' => 'tenant',
            'subject_uuid' => 'tenantA',
            'plan_uuid' => self::PLATFORM_FREE,
            'plan_key' => 'free',
            'status' => 'active',
        ], $overrides));

        return $uuid;
    }

    // ---------------------------------------------------------------
    // Spec §8 -- the poisoned-transaction failure mode (the reason this
    // suite exists: SQLite cannot exercise it, only a real PostgreSQL can).
    // ---------------------------------------------------------------

    public function testSavepointIsolationLeavesTheOuterTransactionUsableForASubsequentWrite(): void
    {
        $subject = Subject::tenant('tenantA');
        $service = $this->service();

        $winnerUuid = db($this->context)->transaction(function () use ($subject, $service): string {
            // Pre-insert the winner INSIDE this already-open outer transaction:
            // the service's own nested insert below then loses the subject
            // unique constraint while the outer transaction is still active.
            $winnerUuid = $this->seedSubscriptionRow(['uuid' => 'pg-winner001']);

            // Same plan -> idempotent: no exception, the lost race just reads
            // the winner back. The unique violation happens inside startFor()'s
            // nested transaction (a real PostgreSQL SAVEPOINT). Without that
            // isolation, PostgreSQL would abort this WHOLE outer transaction
            // right here, and every statement below would fail.
            $result = $service->startFor($subject, self::PLATFORM_FREE);
            self::assertSame($winnerUuid, $result['uuid']);

            // Prove the outer transaction is NOT poisoned: an unrelated write,
            // issued AFTER the violation, in the SAME transaction, still
            // succeeds. This is the failure mode the savepoint exists to
            // prevent -- a poisoned PostgreSQL transaction would reject this
            // statement with "current transaction is aborted".
            $this->connection->table('subscription_events')->insert([
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $subject->tenantUuid,
                'subject_type' => $subject->type,
                'subject_uuid' => $subject->uuid,
                'type' => 'poisoned_transaction_probe',
                'from_status' => null,
                'to_status' => 'active',
                'source' => 'manual',
            ]);

            return $winnerUuid;
        });

        self::assertSame('pg-winner001', $winnerUuid);
        // db()->transaction() only rolls back on an uncaught exception -- it
        // returned normally above, so the outer transaction committed.
        self::assertSame(0, db($this->context)->transactionLevel());

        // The write issued AFTER the savepoint-isolated violation actually
        // persisted: a poisoned transaction would have forced a ROLLBACK that
        // discarded it along with everything else in the same transaction.
        $probe = $this->connection->table('subscription_events')
            ->where('type', '=', 'poisoned_transaction_probe')
            ->first();
        self::assertNotNull($probe, 'the post-violation write did not survive the outer transaction');

        // The pre-inserted winner itself also survived the commit.
        self::assertSame(1, $this->connection->table('subscriptions')->count());
    }

    // ---------------------------------------------------------------
    // Same deterministic outcomes as SQLite (spec §8) -- proven again here
    // against the real driver the savepoint logic targets.
    // ---------------------------------------------------------------

    public function testLostRaceOnTheSamePlanReturnsTheWinnerRowIdempotently(): void
    {
        $subject = Subject::tenant('tenantA');
        $winnerUuid = $this->seedSubscriptionRow(['uuid' => 'pg-winner002']);

        $row = $this->service()->startFor($subject, self::PLATFORM_FREE);

        self::assertSame($winnerUuid, $row['uuid']);
        self::assertSame(self::PLATFORM_FREE, $row['plan_uuid']);
        self::assertSame(1, $this->connection->table('subscriptions')->count());
        // Idempotent: the loser appends NO duplicate `created` event.
        self::assertSame(
            0,
            $this->connection->table('subscription_events')
                ->where('tenant_uuid', '=', 'tenantA')
                ->count()
        );
    }

    public function testLostRaceOnADifferentPlanRaisesTheConflictAndChangesNothing(): void
    {
        $subject = Subject::tenant('tenantA');
        $this->seedSubscriptionRow(['uuid' => 'pg-winner003']);

        try {
            $this->service()->startFor($subject, self::PLATFORM_PRO);
            self::fail('Expected a SubscriptionConflictException.');
        } catch (SubscriptionConflictException) {
        }

        // The connection is still usable afterwards -- the domain exception
        // propagated out of a TOP-LEVEL transaction (a real ROLLBACK), which
        // always leaves PostgreSQL in a clean, queryable state.
        $rows = $this->connection->table('subscriptions')->get();
        self::assertCount(1, $rows);
        self::assertSame('pg-winner003', $rows[0]['uuid']);
        self::assertSame(self::PLATFORM_FREE, $rows[0]['plan_uuid']);
        self::assertSame(
            0,
            $this->connection->table('subscription_events')
                ->where('tenant_uuid', '=', 'tenantA')
                ->count()
        );
    }
}
