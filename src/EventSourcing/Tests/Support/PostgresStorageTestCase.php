<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\Testing\PostgresTestSupport;

abstract class PostgresStorageTestCase extends TestCase
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
    ): void {
        $connection = PostgresTestSupport::sharedConnection($this->postgresDsn());
        $prefix = PostgresTestSupport::uniquePrefix();
        $schema = PostgresTestSupport::schemaForImplementation($prefix, $implementation, $lockStrategy);

        try {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $store = PostgresTestSupport::storeForImplementation($connection, $prefix, $implementation, lockStrategy: $lockStrategy);

            $callback($store, $connection, $prefix);
        } finally {
            PostgresTestSupport::resetSharedConnection($connection);

            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $domainIds
     * @param array<string, mixed>  $metadata
     */
    protected static function event(string $eventType, array $payload = [], array $domainIds = [], array $metadata = []): Event
    {
        return new Event(
            eventType: $eventType,
            payload: $payload,
            domainIds: $domainIds,
            metadata: $metadata,
        );
    }
}
