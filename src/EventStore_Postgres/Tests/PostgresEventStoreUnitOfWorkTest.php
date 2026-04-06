<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\PostgresEventStore;

require_once __DIR__.'/PostgresTestSupport.php';

final class PostgresEventStoreUnitOfWorkTest extends TestCase
{
    public function testFlushPersistsAppendsThatDependOnEarlierBufferedEvents(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres event-store integration tests.');
        }

        $connection = postgresTestConnection($dsn);
        $prefix = postgresTestUniquePrefix();
        $schema = postgresTestSchema($prefix);

        try {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $store = postgresTestStore($connection, $prefix);
            $uow = new EventStoreUnitOfWork($store);
            $query = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
            )->ofTypes('course.created');

            $firstVersion = $uow->load($query)->queryVersion;
            $uow->append([
                new AppendableEvent(
                    eventType: 'course.created',
                    payload: ['name' => 'Math 101'],
                    domainIds: ['courseId' => 'course-123'],
                ),
            ], $firstVersion);

            $secondVersion = $uow->load($query)->queryVersion;
            $uow->append([
                new AppendableEvent(
                    eventType: 'course.created',
                    payload: ['name' => 'Math 102'],
                    domainIds: ['courseId' => 'course-123'],
                ),
            ], $secondVersion);

            $connection->beginTransaction();
            try {
                $uow->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            $persisted = $store->load($query);
            self::assertCount(2, $persisted->events);
            self::assertSame('Math 101', $persisted->events[0]->payload['name']);
            self::assertSame('Math 102', $persisted->events[1]->payload['name']);
        } finally {
            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $connection->close();
        }
    }
}
