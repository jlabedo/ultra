<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;

use function Ultra\EventStore_Postgres\Tests\postgresTestConnection;
use function Ultra\EventStore_Postgres\Tests\postgresTestSchemaForImplementation;
use function Ultra\EventStore_Postgres\Tests\postgresTestStoreForImplementation;
use function Ultra\EventStore_Postgres\Tests\postgresTestUniquePrefix;

require_once dirname(__DIR__).'/PostgresTestSupport.php';

abstract class PostgresEventStoreTestCase extends TestCase
{
    protected function postgresDsn(): string
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres event-store integration tests.');
        }

        return $dsn;
    }

    /**
     * @param callable(EventStore, Connection, string): void $callback
     */
    protected function withPostgresStore(
        callable $callback,
        string $implementation = 'hstore',
        string $lockStrategy = 'shared-exclusive',
    ): void
    {
        $connection = postgresTestConnection($this->postgresDsn());
        $prefix = postgresTestUniquePrefix();
        $schema = postgresTestSchemaForImplementation($prefix, $implementation, $lockStrategy);

        try {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $store = postgresTestStoreForImplementation($connection, $prefix, $implementation, lockStrategy: $lockStrategy);

            $callback($store, $connection, $prefix);
        } finally {
            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $connection->close();
        }
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $domainIds
     */
    protected static function event(string $eventType, array $payload = [], array $domainIds = []): Event
    {
        return new Event(
            eventType: $eventType,
            payload: $payload,
            domainIds: $domainIds,
        );
    }
}
