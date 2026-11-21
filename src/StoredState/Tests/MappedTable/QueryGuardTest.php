<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\Exception\PendingQueryChangesException;
use Ultra\MappedTable\GuardLockAdapter;
use Ultra\MappedTable\GuardLockMode;
use Ultra\MappedTable\InMemoryGuardLockAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresGuardLockAdapter;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Tests\Support\RowSetFixture;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class QueryGuardTest extends RowSetTestCase
{
    public function test_direct_writers_and_owner_cleanup_consolidate_guards_before_query_replay(): void
    {
        $root = $this->fixture();
        $rows = $root->defined(GuardedBookingDefinition::at($root->tableName));
        [$owned, $lines] = $this->lineCollection(storage: $root->storage);
        $storage = $root->storage;
        assert($storage instanceof InMemoryRowDatabase);
        $trace = new QueryGuardTrace($storage);
        $unit = new MappedTableUnitOfWork(
            [GuardedBookingDefinition::class => $rows, GuardedLineDefinition::class => $lines],
            queryGuards: [
                new QueryGuardDefinition('z', [
                    new QueryGuardDependency(GuardedBookingDefinition::class, 'status', ['note']),
                    new QueryGuardDependency(GuardedLineDefinition::class, 'owner', []),
                ]),
                new QueryGuardDefinition('a', [new QueryGuardDependency(GuardedBookingDefinition::class, 'id', [])]),
            ],
            guardLocks: $trace,
        );

        $root->seed(['id' => 'booking', 'status' => '10', 'note' => 'original']);
        $owner = new MappedKey(['owner' => '10']);
        $line = $owner->mergedWith(new MappedKey(['id' => 'item']));
        $owned->seed([...$line->values, 'note' => 'line']);
        $unit->queryGuard('z')->observe(10, static function () use ($root, $owned, $line, $trace): array {
            $trace->events[] = 'query';

            return [$root->stored('booking', ['status']), $owned->stored($line, ['note'])];
        });
        $unit->queryGuard('a')->lock('20');
        $unit->queryGuard('a')->lock('3');
        $unit->lockRow('write', new MappedKey(['id' => '2']));
        $unit->lockRow('read', new MappedKey(['id' => '2']));
        $unit->lockRow('read', new MappedKey(['id' => '1']));
        $unit->lockRow('read', new MappedKey(['id' => '1']));
        $rows->withKey('booking')->expectColumnValue('status', '10')->setColumn('status', '20');
        $lines->reconcileForOwner($owner, []);

        static::assertSame(['query'], $trace->events);
        $root->flush($unit);
        static::assertSame(
            [
                'query',
                'a:20:SHARED',
                'a:3:SHARED',
                'z:10:EXCLUSIVE',
                'z:20:EXCLUSIVE',
                'read:manual:{"id":"1"}:EXCLUSIVE',
                'read:manual:{"id":"2"}:EXCLUSIVE',
                'write:manual:{"id":"2"}:EXCLUSIVE',
                'query',
            ],
            $trace->events,
        );
        static::assertSame(['status' => '20'], $root->stored('booking', ['status']));
        static::assertFalse($owned->stored($line, ['note']));
        static::assertFalse($unit->hasPendingChanges());
        $unit->flush();
        static::assertCount(9, $trace->events);
    }

    /** @return iterable<string, array{string, int|string, ?class-string<\Throwable>}> */
    public static function pendingReads(): iterable
    {
        yield 'watched update' => ['watched', '10', PendingQueryChangesException::class];
        yield 'insert' => ['insert', '10', PendingQueryChangesException::class];
        yield 'delete' => ['delete', '10', PendingQueryChangesException::class];
        yield 'blind upsert' => ['upsert', '10', \LogicException::class];
        yield 'unwatched update' => ['unwatched', '10', null];
        yield 'unchanged watched value' => ['unchanged', '10', null];
        yield 'another value' => ['watched', '30', null];
        yield 'before a move' => ['move', 10, PendingQueryChangesException::class];
        yield 'after a move' => ['move', '20', PendingQueryChangesException::class];
        yield 'unknown previous value' => ['unknown', '30', \LogicException::class];
        yield 'check only' => ['check', '10', null];
    }

    #[DataProvider('pendingReads')]
    public function test_initial_read_checks_the_pending_plan_before_calling_the_query(
        string $mutation,
        int|string $value,
        ?string $failure,
    ): void {
        [$root, $rows, $unit] = $this->bookings();
        if (!in_array($mutation, ['insert', 'upsert'], true)) {
            $root->seed(['id' => 'booking', 'status' => '10', 'note' => 'original', 'version_a' => 'one']);
        }

        $row = $rows->withKey('booking');
        if (!in_array($mutation, ['insert', 'upsert', 'unknown'], true)) {
            $row->expectColumnValue('status', '10');
        }
        match ($mutation) {
            'watched', 'unknown' => $row->setColumn('note', 'changed'),
            'insert' => $row->insert()->setColumn('status', '10'),
            'upsert' => $row->upsert()->setColumn('status', '10'),
            'delete' => $row->delete(),
            'unwatched' => $row->setColumn('version_a', 'two'),
            'unchanged' => $row->expectColumnValue('note', 'original')->setColumns([
                'note' => 'original',
                'version_a' => 'two',
            ]),
            'move' => $row->setColumn('status', '20'),
            'check' => null,
        };
        $calls = 0;
        try {
            $unit->queryGuard('booking')->observe($value, static function () use (&$calls): int {
                return ++$calls;
            });
            static::assertNull($failure);
            static::assertSame(1, $calls);
        } catch (\LogicException $exception) {
            static::assertSame($failure, $exception::class);
            if ($exception instanceof PendingQueryChangesException) {
                static::assertSame('booking', $exception->guardName);
                static::assertSame($value, $exception->guardValue);
                static::assertSame(GuardedBookingDefinition::class, $exception->rowSet);
                static::assertSame('booking', $exception->key->value('id'));
            }
            static::assertSame(0, $calls);
            $rows->clear();
            static::assertFalse($unit->hasPendingChanges());
        }
    }

    public function test_failed_replay_leaves_writes_unapplied_and_clear_discards_the_observation(): void
    {
        [$root, $rows, $unit] = $this->bookings();
        $root->seed(['id' => 'booking', 'status' => '10', 'note' => 'original']);
        $result = 1;
        $read = static function () use (&$result): int|string {
            return $result;
        };

        static::assertSame(1, $unit->queryGuard('booking')->observe('10', $read));
        static::assertTrue($unit->hasPendingChanges());
        $rows->withKey('booking')->expectColumnValue('status', '10')->setColumn('note', 'changed');
        $result = '1';
        try {
            $root->flush($unit);
            static::fail('A replay compares PHP types strictly.');
        } catch (ConcurrencyException $exception) {
            static::assertStringContainsString('booking(10)', $exception->getMessage());
            static::assertSame(['note' => 'original'], $root->stored('booking', ['note']));
            static::assertTrue($unit->hasPendingChanges());
        }

        $unit->clear();
        static::assertFalse($unit->hasPendingChanges());
        static::assertSame('1', $unit->queryGuard('booking')->observe('10', $read));
        $root->flush($unit);
        static::assertFalse($unit->hasPendingChanges());
    }

    public function test_empty_reconciliation_with_an_unknown_guard_value_is_rejected(): void
    {
        [$fixture, $lines] = $this->lineCollection();
        $locks = new InMemoryGuardLockAdapter($fixture->storage);
        $unit = new MappedTableUnitOfWork(
            [GuardedLineDefinition::class => $lines],
            queryGuards: [new QueryGuardDefinition('item', [new QueryGuardDependency(
                GuardedLineDefinition::class,
                'id',
            )])],
            guardLocks: $locks,
        );
        $lines->reconcileForOwner(new MappedKey(['owner' => 'one']), []);
        $this->expectExceptionMessage('requires reconciliation to fix its value column "id" in the owner key');
        $fixture->flush($unit);
    }

    public function test_empty_reconciliation_rejects_an_initial_read_of_that_owner(): void
    {
        [$fixture, $lines] = $this->lineCollection();
        $owner = new MappedKey(['owner' => 'one']);
        $unit = new MappedTableUnitOfWork(
            [GuardedLineDefinition::class => $lines],
            queryGuards: [new QueryGuardDefinition('membership', [
                new QueryGuardDependency(GuardedLineDefinition::class, 'owner', []),
            ])],
            guardLocks: new InMemoryGuardLockAdapter($fixture->storage),
        );
        $lines->reconcileForOwner($owner, []);

        try {
            $unit->queryGuard('membership')->observe('one', static fn() => static::fail('The query must not execute.'));
            static::fail('The reconciliation affects the query.');
        } catch (PendingQueryChangesException $exception) {
            static::assertSame($owner, $exception->key);
            $lines->clear();
            static::assertFalse($unit->hasPendingChanges());
        }
    }

    public function test_an_invalid_query_result_does_not_register_a_reader_lock(): void
    {
        [, , $unit] = $this->bookings();
        try {
            $unit->queryGuard('booking')->observe('one', static fn(): array => [new \stdClass()]);
            static::fail('The observation must have a stable result.');
        } catch (\InvalidArgumentException) {
            static::assertFalse($unit->hasPendingChanges());
        }
    }

    public function test_flushing_a_manual_lock_requires_a_guard_adapter(): void
    {
        $unitOfWork = new MappedTableUnitOfWork([]);
        $unitOfWork->lockRow('companies', new MappedKey(['id' => 'company']));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('GuardLockAdapter');
        $unitOfWork->flush();
    }

    public function test_even_a_guard_only_flush_requires_the_watched_row_sets_transaction(): void
    {
        $fixture = $this->fixture();
        $rows = $fixture->defined(GuardedBookingDefinition::at($fixture->tableName));
        $differentStorage = new InMemoryRowDatabase();
        $unit = new MappedTableUnitOfWork(
            [GuardedBookingDefinition::class => $rows],
            queryGuards: [new QueryGuardDefinition('booking', [
                new QueryGuardDependency(GuardedBookingDefinition::class, 'id'),
            ])],
            guardLocks: new InMemoryGuardLockAdapter($differentStorage),
        );
        $unit->queryGuard('booking')->lock('booking');
        $differentStorage->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('same transaction resource');
            $fixture->flush($unit);
        } finally {
            $differentStorage->rollBack();
        }
    }

    public function test_empty_reconciliation_waits_for_a_postgres_query_reader_of_the_same_owner(): void
    {
        [$fixture, $lines] = $this->lineCollection('postgres');
        $connection = $fixture->connection();
        $name = 'membership_' . $fixture->tableName;
        $definitions = [new QueryGuardDefinition($name, [new QueryGuardDependency(
            GuardedLineDefinition::class,
            'owner',
            [],
        )])];
        $reader = new MappedTableUnitOfWork(
            [GuardedLineDefinition::class => $lines],
            queryGuards: $definitions,
            guardLocks: new PostgresGuardLockAdapter($connection),
        );
        $owner = new MappedKey(['owner' => 'one']);
        $count = static fn(): int => (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $connection->quoteSingleIdentifier($fixture->tableName) . ' WHERE owner = ?',
            ['one'],
        );
        static::assertSame(0, $reader->queryGuard($name)->observe('one', $count));
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        assert(is_string($dsn));
        $other = PostgresConnectionSupport::connection($dsn);

        try {
            $otherLines = new MappedRowSet(
                new PostgresMappedRowSetAdapter($other, $fixture->tableName, ['owner', 'id']),
                GuardedLineDefinition::at($fixture->tableName),
            );
            $writer = new MappedTableUnitOfWork(
                [GuardedLineDefinition::class => $otherLines],
                queryGuards: $definitions,
                guardLocks: new PostgresGuardLockAdapter($other),
            );
            $connection->beginTransaction();
            $reader->flush();
            $otherLines->reconcileForOwner(new MappedKey(['owner' => 'two']), []);
            $other->transactional($writer->flush(...));

            $otherLines->reconcileForOwner($owner, []);
            try {
                $other->transactional(static function () use ($other, $writer): void {
                    $other->executeStatement("SET LOCAL lock_timeout = '50ms'");
                    $writer->flush();
                });
                static::fail('The empty reconciliation must take the writer lock.');
            } catch (DriverException $exception) {
                static::assertStringContainsString('lock timeout', $exception->getMessage());
                static::assertTrue($writer->hasPendingChanges());
            }

            $connection->commit();
            $other->transactional($writer->flush(...));
            static::assertFalse($writer->hasPendingChanges());

            static::assertSame(0, $reader->queryGuard($name)->observe('one', $count));
            $otherLines->withKey($owner->mergedWith(new MappedKey(['id' => 'inserted'])))->insert();
            $other->transactional($writer->flush(...));
            $this->expectException(ConcurrencyException::class);
            $fixture->flush($reader);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $other->close();
        }
    }

    /** @return array{RowSetFixture, MappedRowSet, MappedTableUnitOfWork} */
    private function bookings(): array
    {
        $fixture = $this->fixture();
        $rows = $fixture->defined(GuardedBookingDefinition::at($fixture->tableName));
        $unit = new MappedTableUnitOfWork(
            [GuardedBookingDefinition::class => $rows],
            queryGuards: [new QueryGuardDefinition('booking', [
                new QueryGuardDependency(GuardedBookingDefinition::class, 'status', ['note']),
            ])],
            guardLocks: new InMemoryGuardLockAdapter($fixture->storage),
        );

        return [$fixture, $rows, $unit];
    }

    /** @return array{RowSetFixture, MappedRowSet} */
    private function lineCollection(string $backend = 'memory', ?InMemoryRowDatabase $storage = null): array
    {
        $fixture = $this->fixture($backend, ['owner', 'id'], storage: $storage);

        return [$fixture, $fixture->defined(GuardedLineDefinition::at($fixture->tableName))];
    }
}

final class QueryGuardTrace implements GuardLockAdapter
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        private readonly InMemoryRowDatabase $database,
    ) {}

    public function activeTransactionResource(): object
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Expected an active test transaction.');
        }

        return $this->database;
    }

    public function acquire(string $namespace, string $key, GuardLockMode $mode): void
    {
        $this->events[] = str_replace('ultra:query_guard:', '', $namespace) . ':' . $key . ':' . $mode->name;
    }
}

final class GuardedBookingDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return self::at('guarded_booking');
    }

    public static function at(string $table): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            $table,
            [
                'id' => new MappedColumn('string'),
                'status' => new MappedColumn('string'),
                'note' => new MappedColumn('string', nullable: true),
                'version_a' => new MappedColumn('string', nullable: true),
            ],
            ['id'],
        );
    }
}

final class GuardedLineDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return self::at('guarded_line');
    }

    public static function at(string $table): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            $table,
            [
                'owner' => new MappedColumn('string'),
                'id' => new MappedColumn('string'),
                'note' => new MappedColumn('string', nullable: true),
            ],
            ['owner', 'id'],
            ownerColumns: ['owner'],
        );
    }
}
