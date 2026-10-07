<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\StorageTestCase;

/** @internal */
#[CoversNamespace('Ultra\EventStore_Postgres')]
final class AppendTransactionTest extends StorageTestCase
{
    #[DataProvider('postgresBackends')]
    public function test_append_joins_the_callers_transaction_and_rollback_removes_the_complete_batch(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $connection = $fixture->connection;
        $query = StreamQuery::forStream(new DomainIdentifier('course', 'c1'));
        $connection->beginTransaction();
        $fixture->store->append([
            self::event('created', ['course' => 'c1']),
            self::event('renamed', ['course' => 'c1']),
        ]);
        static::assertTrue($connection->isTransactionActive());
        static::assertCount(2, $fixture->store->load($query)->events);
        $connection->rollBack();
        static::assertSame([], $fixture->store->load($query)->events);
        $fixture->store->append([self::event('created', ['course' => 'c1'])]);
        static::assertFalse($connection->isTransactionActive());
        static::assertCount(1, $fixture->store->load($query)->events);
        try {
            $fixture->store->append([
                self::event('renamed', ['course' => 'c1']),
                self::event('renamed', ['course' => 'c1'], ['invalid' => NAN]),
            ]);
            static::fail('Encoding failure must reject the entire append and close its own transaction.');
        } catch (\JsonException) {
        }
        static::assertFalse($connection->isTransactionActive());
        static::assertCount(1, $fixture->store->load($query)->events);
    }
}
