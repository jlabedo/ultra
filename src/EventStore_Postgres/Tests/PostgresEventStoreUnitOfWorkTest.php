<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;
use Ultra\EventStore_Postgres\Tests\Support\PostgresEventStoreTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class PostgresEventStoreUnitOfWorkTest extends PostgresEventStoreTestCase
{
    public function test_flush_persists_appends_that_depend_on_earlier_buffered_events(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store, Connection $connection): void {
            $uow = new EventStoreUnitOfWork($store);
            $query = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
            )->ofTypes('course.created');

            $firstQuery = $uow->load($query)->versionedQuery;
            $uow->append([
                self::event(
                    'course.created',
                    ['name' => 'Math 101'],
                    ['courseId' => 'course-123'],
                ),
            ], $firstQuery);

            $secondQuery = $uow->load($query)->versionedQuery;
            $uow->append([
                self::event(
                    'course.created',
                    ['name' => 'Math 102'],
                    ['courseId' => 'course-123'],
                ),
            ], $secondQuery);

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
        });
    }
}
