<?php

declare(strict_types=1);

namespace Glueful\Extensions\Subscriptions\Tests\Integration\Schema;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Extensions\Subscriptions\Schema\SubscriptionsSchemaVerifier;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

/**
 * The adoption contract (schema policy spec B7), proven over the whole chain: for every shipped
 * migration, the verifier is FALSE on the sequential predecessor fixture (all earlier
 * migrations applied, this one not) and TRUE after this one applies. Subscriptions folds no later
 * effect into an earlier create, so the sequential chain is a valid incomplete-predecessor
 * fixture for every basename — 006 passes its prepared-upgrade guard on a fresh (row-free)
 * chain, and the subject-model/ALTER proofs are false while only their parent tables exist.
 */
final class SchemaVerifierBehaviorTest extends TestCase
{
    private Connection $connection;
    private MigrationManager $manager;
    private SubscriptionsSchemaVerifier $verifier;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/subs-verify-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
        $this->manager = new MigrationManager(
            dirname(__DIR__, 3) . '/migrations',
            new FileFinder(),
            null,
            $this->connection
        );
        $this->verifier = new SubscriptionsSchemaVerifier();
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
    }

    public function testEveryMigrationProofFlipsExactlyWithItsOwnMigration(): void
    {
        foreach ($this->verifier->migrationBasenames() as $basename) {
            self::assertFalse(
                $this->verifier->verify($this->connection, $basename),
                "{$basename}: proof must be FALSE on its incomplete predecessor fixture"
            );
            $result = $this->manager->migrate(dirname(__DIR__, 3) . '/migrations/' . $basename);
            self::assertSame([], $result['failed'], "fixture migration {$basename} must apply");
            self::assertTrue(
                $this->verifier->verify($this->connection, $basename),
                "{$basename}: proof must be TRUE once its migration ran"
            );
        }
    }

    public function testUnknownBasenameIsNeverAdoptable(): void
    {
        self::assertFalse($this->verifier->verify($this->connection, '999_Unknown.php'));
    }
}
