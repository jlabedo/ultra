<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\Exception\PendingQueryChangesException;
use Ultra\MappedTable\Exception\UnknownQueryGuardException;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\QueryGuard;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Schema\SchemaRowSet;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;
use Ultra\Sql\ColumnType;
use Ultra\StateStore\Tests\Support\StateStoreSchema;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;

/** @internal */
final class QueryReadOrderingTest extends StateStoreTestCase
{
    public static function unknownGuards(): iterable
    {
        yield 'missing definition' => ['company_usgae'];
        yield 'empty name' => [''];
    }

    #[DataProvider('unknownGuards')]
    public function test_unknown_guards_are_rejected_at_lookup_without_queuing_work(string $name): void
    {
        $unitOfWork = new MappedTableUnitOfWork([], new InMemoryMappedTableAdapter(new InMemoryRowDatabase()));
        try {
            $unitOfWork->queryGuard($name);
            static::fail('An unknown guard must fail.');
        } catch (UnknownQueryGuardException $exception) {
            static::assertSame($name, $exception->guardName);
        }
        static::assertFalse($unitOfWork->hasPendingChanges());
    }

    public static function impactingMutations(): iterable
    {
        yield 'insert' => ['insert', 'acme'];
        yield 'update' => ['update', 'acme'];
        yield 'writer after an unrelated check-only row' => ['update_after_check', 'acme'];
        yield 'delete' => ['delete', 'acme'];
        yield 'move from queried scope' => ['move', 'acme'];
        yield 'move into queried scope' => ['move', 'globex'];
        yield 'scalar scope shares its string lock identity' => ['numeric', '123'];
    }

    #[DataProvider('impactingMutations')]
    public function test_affected_reads_are_rejected_before_executing_or_registering_the_query(
        string $mutation,
        string $scope,
    ): void {
        [, $table, , $registry, $guard] = $this->fixture(numeric: 'numeric' === $mutation);
        if ('update_after_check' === $mutation) {
            $table->withKey('a-check-only')->expectColumnValue('company_id', 'acme');
        }
        $row = $table->withKey('booking-1');
        if ('insert' === $mutation || 'numeric' === $mutation) {
            $row->insert()->setColumns(['company_id' => 'numeric' === $mutation ? 123 : 'acme', 'minutes' => 60]);
        } else {
            $row->expectColumnValue('company_id', 'acme');
            match ($mutation) {
                'update', 'update_after_check' => $row->setColumn('minutes', 120),
                'delete' => $row->delete(),
                'move' => $row->setColumn('company_id', 'globex'),
            };
        }
        $called = false;
        try {
            $guard->observe($scope, static function () use (&$called): int {
                $called = true;

                return 0;
            });
            static::fail('An affected query must not execute against committed rows alone.');
        } catch (PendingQueryChangesException $exception) {
            static::assertSame('company_usage', $exception->guardName);
            static::assertSame($scope, $exception->guardValue);
            static::assertSame(QueryReadSchema::BOOKINGS, $exception->rowSet);
            static::assertSame('booking-1', $exception->key->value('id'));
            static::assertStringContainsString('before changing mapped row set', $exception->getMessage());
        }
        static::assertFalse($called);
        // Rejecting a read must add neither a shared guard nor a query expectation.
        $table->clear();
        static::assertFalse($registry->hasPendingChanges());
        static::assertSame(0, $guard->observe($scope, static fn(): int => 0));
    }

    public static function unrelatedChanges(): iterable
    {
        yield 'different scope' => ['scope'];
        yield 'move between other scopes' => ['move'];
        yield 'unwatched column' => ['column'];
        yield 'another guard on the same table' => ['guard'];
        yield 'different table' => ['table'];
        yield 'optimistic expectations alone' => ['expectation'];
        yield 'unchanged observed values' => ['unchanged'];
        yield 'manual guard alone' => ['lock'];
    }

    #[DataProvider('unrelatedChanges')]
    public function test_non_impacting_work_does_not_prevent_observation(string $change): void
    {
        [, $table, $otherTable, $registry, $guard] = $this->fixture();
        $row = $table->withKey('booking-1');
        match ($change) {
            'scope' => $row->insert()->setColumns(['company_id' => 'globex', 'minutes' => 60]),
            'move' => $row->expectColumnValue('company_id', 'globex')->setColumn('company_id', 'initech'),
            'column' => $row->setColumn('note', 'updated'),
            'guard' => $row->expectColumnValue('room_id', 'blue')->setColumn('status', 'cancelled'),
            'table' => $otherTable->withKey('note-1')->insert()->setColumn('text', 'hello'),
            'expectation' => $row->expectColumnValue('company_id', 'acme'),
            'unchanged' => $row->expectColumnValues(['company_id' => 'acme', 'minutes' => 60])->setColumn(
                'minutes',
                60,
            ),
            'lock' => $registry->lockRow('bookings', new \Ultra\MappedTable\MappedKey(['id' => 'booking-1'])),
        };

        static::assertSame(42, $guard->observe('acme', static fn(): int => 42));
    }

    public function test_unknown_previous_scope_is_rejected_without_executing_the_query(): void
    {
        [, $table, , , $guard] = $this->fixture();
        $table->withKey('booking-1')->setColumn('minutes', 60);
        $called = false;
        try {
            $guard->observe('acme', static function () use (&$called): int {
                $called = true;

                return 0;
            });
            static::fail('An unknown old scope cannot be assumed unrelated.');
        } catch (\LogicException $exception) {
            static::assertStringContainsString('requires an expectation on "company_id"', $exception->getMessage());
        }
        static::assertFalse($called);
    }

    public function test_flush_replays_an_earlier_observation_even_with_subsequent_affected_writes(): void
    {
        [$database, $table, , $registry, $guard] = $this->fixture();
        $phases = [];
        $guard->observe('acme', static function () use ($database, &$phases): bool {
            $phases[] = $database->isTransactionActive();

            return false !== $database->row('bookings', 'id', 'booking-1');
        });
        $table
            ->withKey('booking-1')
            ->insert()
            ->setColumns(['company_id' => 'acme', 'room_id' => 'blue', 'minutes' => 60]);
        $database->beginTransaction();
        try {
            $registry->flush();
            $database->commit();
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
        }
        static::assertSame([false, true], $phases);
        static::assertSame(60, $database->row('bookings', 'id', 'booking-1')['minutes']);
        $registry->clear();
        static::assertTrue($guard->observe('acme', static fn(): bool => true));
    }

    /** @return array{InMemoryRowDatabase, MappedRowSet, MappedRowSet, MappedTableUnitOfWork, QueryGuard} */
    private function fixture(bool $numeric = false): array
    {
        $database = new InMemoryRowDatabase();
        $adapter = new InMemoryMappedTableAdapter($database);
        $schema = QueryReadSchema::definition($numeric);
        $table = $adapter->compileRowSet(SchemaRowSet::fromTable($schema, QueryReadSchema::BOOKINGS))();
        $notes = $adapter->compileRowSet(SchemaRowSet::fromTable($schema, QueryReadSchema::NOTES))();
        $unitOfWork = new MappedTableUnitOfWork(
            [QueryReadSchema::BOOKINGS => $table, QueryReadSchema::NOTES => $notes],
            $adapter,
            queryGuards: [
                new QueryGuardDefinition('company_usage', [
                    new QueryGuardDependency(QueryReadSchema::BOOKINGS, 'company_id', ['charged', 'minutes']),
                ]),
                new QueryGuardDefinition('room_bookings', [
                    new QueryGuardDependency(QueryReadSchema::BOOKINGS, 'room_id', ['status']),
                ]),
            ],
        );

        return [$database, $table, $notes, $unitOfWork, $unitOfWork->queryGuard('company_usage')];
    }

    #[DataProvider('backends')]
    public function test_deletion_rejects_affected_query_reads_but_replays_earlier_observations_before_writes(string $backend): void
    {
        $fixture = $this->stateFixture($backend, [OrderWithLinesState::class]);
        $fixture->store->save(new OrderWithLinesState('one', 'pending', [new OrderLineState('line', 'SKU', 1)]));
        $fixture->flush();
        $fixture->unitOfWork->clear();
        [$store, $registry] = $fixture->newStore([OrderWithLinesState::class], guards: [
            new QueryGuardDefinition('usage', [
                new QueryGuardDependency(StateStoreSchema::ORDERS, 'order_id', []),
                new QueryGuardDependency(StateStoreSchema::ORDER_LINES, 'order_id'),
            ]),
        ]);
        $guard = $registry->queryGuard('usage');
        $scope = 'one';
        $reads = [];
        $guard->observe($scope, static function () use ($fixture, &$reads): int {
            $count = count($fixture->store->load(OrderWithLinesState::class, 'one')->lines);
            $reads[] = $count;

            return $count;
        });
        $store->observe(OrderWithLinesState::class, 'one');
        $store->delete(OrderWithLinesState::class, 'one');
        try {
            $guard->observe(
                $scope,
                static fn(): int => throw new \RuntimeException('Must not read pending deletions.'),
            );
            static::fail('An affected query must be rejected before reading storage.');
        } catch (PendingQueryChangesException $exception) {
            static::assertSame($scope, $exception->guardValue);
        }
        static::assertSame(1, $guard->observe('unrelated', static fn(): int => 1));
        $fixture->flush($registry);
        static::assertSame([1, 1], $reads);
        $registry->clear();
        static::assertNull($store->load(OrderWithLinesState::class, 'one'));
    }
}

final class QueryReadSchema
{
    public const string BOOKINGS = 'bookings';
    public const string NOTES = 'notes';

    public static function definition(bool $numeric): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::BOOKINGS,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('company_id', type: $numeric ? ColumnType::Int32 : ColumnType::Text),
                    new ColumnDefinition('room_id', type: ColumnType::Text),
                    new ColumnDefinition('charged', type: ColumnType::Boolean),
                    new ColumnDefinition('minutes', type: ColumnType::Int32),
                    new ColumnDefinition('status', type: ColumnType::Text),
                    new ColumnDefinition('note', type: ColumnType::Text),
                ],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                self::NOTES,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('text', type: ColumnType::Text),
                ],
                primaryKey: ['id'],
            ),
        ]);
    }
}
