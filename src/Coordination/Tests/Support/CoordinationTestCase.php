<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\Coordination\InMemory\InMemoryCoordinatorLease;
use Ultra\Coordination\InMemory\InMemoryDirtyKeyTracker;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Time\StatickClock;

abstract class CoordinationTestCase extends TestCase
{
    /** @var list<array{Connection, PostgresCoordinatorLeaseSchema|PostgresDirtyKeyTrackerSchema}> */
    private array $tables = [];

    /** @return iterable<string, array{string}> */
    public static function backends(): iterable
    {
        yield 'in memory' => ['memory'];
        yield 'Postgres' => ['postgres'];
    }

    protected function tracker(string $backend): DirtyKeyTracker
    {
        return 'memory' === $backend ? new InMemoryDirtyKeyTracker() : new PostgresDirtyKeyTracker(...$this->postgresTable('dirty_keys'));
    }

    protected function lease(string $backend, StatickClock $clock): CoordinatorLease
    {
        return 'memory' === $backend ? new InMemoryCoordinatorLease($clock) : new PostgresCoordinatorLease(...[...$this->postgresTable('leases'), $clock]);
    }

    /** @return array{Connection, string} */
    protected function postgresTable(string $kind): array
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres coordination guarantees.');
        }
        $connection = PostgresConnectionSupport::connection($dsn);
        $table = 'coordination_guarantees_'.bin2hex(random_bytes(8));
        $schema = 'leases' === $kind ? new PostgresCoordinatorLeaseSchema($table) : new PostgresDirtyKeyTrackerSchema($table);
        $this->tables[] = [$connection, $schema];
        foreach ($schema->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }
        return [$connection, $table];
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->tables) as [$connection, $schema]) {
                PostgresConnectionSupport::resetSharedConnection($connection);
                foreach ($schema->dropStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
                $connection->close();
            }
        } finally {
            $this->tables = [];
            parent::tearDown();
        }
    }
}
