<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\PostgresTestSupport;

abstract class StorageTestCase extends EventStoreTestCase
{
    /** @var list<array{Connection, list<string>}> */
    private array $databases = [];

    /** @return iterable<string, array{string}> */
    public static function backends(): iterable
    {
        yield 'memory' => ['memory'];
        yield from self::postgresBackends();
    }

    /** @return iterable<string, array{string}> */
    public static function postgresBackends(): iterable
    {
        yield 'hstore' => ['hstore'];
        yield 'query-streams' => ['query-streams'];
    }

    protected function fixture(string $backend): EventStoreFixture
    {
        if ('memory' === $backend) {
            return new EventStoreFixture(new InMemoryEventStore());
        }

        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres event-store integration tests.');
        }

        $connection = PostgresConnectionSupport::sharedConnection($dsn);
        $prefix = PostgresTestSupport::uniquePrefix();
        $schema = PostgresTestSupport::schemaForImplementation($prefix, $backend);
        $this->databases[] = [$connection, $schema->dropStatements()];
        foreach ($schema->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        return new EventStoreFixture(
            PostgresTestSupport::storeForImplementation($connection, $prefix, $backend),
            $connection,
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->databases) as [$connection, $dropStatements]) {
            PostgresConnectionSupport::resetSharedConnection($connection);
            foreach ($dropStatements as $statement) {
                $connection->executeStatement($statement);
            }
        }
        $this->databases = [];
        parent::tearDown();
    }
}
