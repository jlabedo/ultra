<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\SchemaMappedRowSet;
use Ultra\MappedTable\Tests\Support\RowSetFixture;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;
use Ultra\UnitOfWork\DbalTransactionManager;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\TransactionManager;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class FlushCoordinationTest extends RowSetTestCase
{
    #[DataProvider('backends')]
    public function test_one_flush_orders_explicit_removals_and_creations_for_composite_owner_keys(string $backend): void
    {
        [$parent, $child, $orders, $lines, $flush] = $this->tables($backend);
        $oldOrder = new MappedKey(['tenant' => 'one', 'id' => 'old']);
        $oldOwner = new MappedKey(['order_tenant' => 'one', 'order_id' => 'old']);
        $oldLine = $oldOwner->mergedWith(new MappedKey(['id' => 'old-line']));
        $prunedLine = $oldOwner->mergedWith(new MappedKey(['id' => 'pruned']));
        $newOrder = new MappedKey(['tenant' => 'one', 'id' => 'new']);
        $newLine = new MappedKey(['order_tenant' => 'one', 'order_id' => 'new', 'id' => 'new-line']);
        $outsideOrder = new MappedKey(['tenant' => 'two', 'id' => 'old']);
        $outsideLine = new MappedKey(['order_tenant' => 'two', 'order_id' => 'old', 'id' => 'old-line']);
        $parent->seed([...$oldOrder->values, 'status' => 'old']);
        $parent->seed([...$outsideOrder->values, 'status' => 'outside']);
        $child->seed([...$oldLine->values, 'status' => 'old']);
        $child->seed([...$prunedLine->values, 'status' => 'old']);
        $child->seed([...$outsideLine->values, 'status' => 'outside']);

        $orders->withKey($oldOrder)->delete();
        $orders->withKey($newOrder)->insert()->setColumn('status', 'new');
        $lines->reconcileForOwner($oldOwner, [new MappedRow($oldLine, [])]);
        $lines->withKey($oldLine)->expectColumnValue('status', 'old')->delete();
        $lines->withKey($newLine)->insert()->setColumn('status', 'new');
        $parent->flush($flush);
        $flush->flush();

        static::assertFalse($parent->stored($oldOrder));
        static::assertFalse($child->stored($oldLine));
        static::assertFalse($child->stored($prunedLine));
        static::assertSame(['status' => 'new'], $parent->stored($newOrder, ['status']));
        static::assertSame(['status' => 'new'], $child->stored($newLine, ['status']));
        static::assertSame(['status' => 'outside'], $child->stored($outsideLine, ['status']));
        static::assertFalse($flush->hasPendingChanges());
    }

    #[DataProvider('backends')]
    public function test_runner_rolls_back_earlier_cleanup_when_a_later_conditional_delete_fails(string $backend): void
    {
        [$parent, $child, $orders, $lines, $flush] = $this->tables($backend);
        $order = new MappedKey(['tenant' => 'one', 'id' => 'old']);
        $owner = new MappedKey(['order_tenant' => 'one', 'order_id' => 'old']);
        $line = $owner->mergedWith(new MappedKey(['id' => 'line']));
        $parent->seed([...$order->values, 'status' => 'actual']);
        $child->seed([...$line->values, 'status' => 'exists']);
        $runner = new TransactionalUnitOfWorkRunner($flush, $this->transactions($parent->storage));

        try {
            $runner->run(static function () use ($orders, $lines, $order, $owner, $line): void {
                $lines->expectSameRowKeysForOwner($owner, [$line]);
                $lines->reconcileForOwner($owner, []);
                $orders->withKey($order)->expectColumnValue('status', 'stale')->delete();
            });
            static::fail('The late conditional delete must reject a stale value.');
        } catch (ConcurrencyException) {
            static::assertFalse($flush->hasPendingChanges());
            static::assertSame(['status' => 'actual'], $parent->stored($order, ['status']));
            static::assertSame(['status' => 'exists'], $child->stored($line, ['status']));
        }

        $runner->run(static function () use ($orders, $lines, $order, $owner): void {
            $lines->reconcileForOwner($owner, []);
            $orders->withKey($order)->expectColumnValue('status', 'actual')->delete();
        });
        static::assertFalse($parent->stored($order));
        static::assertFalse($child->stored($line));
        static::assertFalse($flush->hasPendingChanges());
    }

    public function test_foreign_keys_reject_parent_deletion_without_an_explicit_child_cleanup(): void
    {
        [$parent, $child, $orders, , $flush] = $this->tables('postgres');
        $order = new MappedKey(['tenant' => 'one', 'id' => 'old']);
        $line = new MappedKey(['order_tenant' => 'one', 'order_id' => 'old', 'id' => 'line']);
        $parent->seed($order->values);
        $child->seed($line->values);
        $orders->withKey($order)->delete();

        try {
            $parent->flush($flush);
            static::fail('The database must reject an orphan.');
        } catch (ForeignKeyConstraintViolationException) {
            static::assertNotFalse($parent->stored($order));
            static::assertNotFalse($child->stored($line));
            static::assertTrue($flush->hasPendingChanges());
        }
    }

    #[DataProvider('backends')]
    public function test_whole_table_reconciliation_respects_child_dependencies(string $backend): void
    {
        [$parent, $child, $orders, $lines, $flush] = $this->tables($backend);
        $order = new MappedKey(['tenant' => 'one', 'id' => 'old']);
        $owner = ['order_tenant' => 'one', 'order_id' => 'old'];
        $line = new MappedKey([...$owner, 'id' => 'line']);
        $parent->seed($order->values);
        $child->seed($line->values);
        $orders->reconcileRows([]);
        $lines->reconcileForOwner($owner, [new MappedRow($line, [])]);

        try {
            $parent->flush($flush);
            static::fail('The planned child requires its parent.');
        } catch (\LogicException $exception) {
            static::assertStringContainsString('leaves its parent', $exception->getMessage());
            static::assertNotFalse($parent->stored($order));
            static::assertNotFalse($child->stored($line));
        }

        $lines->reconcileForOwner($owner, []);
        $parent->flush($flush);
        static::assertFalse($parent->stored($order));
        static::assertFalse($child->stored($line));
        static::assertFalse($flush->hasPendingChanges());
    }

    public function test_membership_checks_precede_every_table_write(): void
    {
        [$parent, $child, $orders, $lines, $flush] = $this->tables('memory');
        $order = new MappedKey(['tenant' => 'one', 'id' => 'old']);
        $owner = new MappedKey(['order_tenant' => 'one', 'order_id' => 'old']);
        $line = $owner->mergedWith(new MappedKey(['id' => 'line']));
        $parent->seed([...$order->values, 'status' => 'old']);
        $orders->withKey($order)->setColumn('status', 'changed');
        $lines->expectSameRowKeysForOwner($owner, []);
        $child->seed($line->values);

        $parent->storage->beginTransaction();
        try {
            $flush->flush();
            static::fail('The membership observation must fail.');
        } catch (ConcurrencyException) {
            static::assertSame(['status' => 'old'], $parent->stored($order, ['status']));
            static::assertTrue($flush->hasPendingChanges());
        } finally {
            $parent->storage->rollBack();
            $flush->clear();
        }
    }

    public function test_known_child_write_conflicting_with_parent_removal_is_rejected_before_writing(): void
    {
        [$parent, $child, $orders, $lines, $flush] = $this->tables('memory');
        $order = new MappedKey(['tenant' => 'one', 'id' => 'old']);
        $line = new MappedKey(['order_tenant' => 'one', 'order_id' => 'old', 'id' => 'line']);
        $parent->seed($order->values);
        $orders->withKey($order)->delete();
        $lines->withKey($line)->insert();

        $parent->storage->beginTransaction();
        try {
            $flush->flush();
            static::fail('The planned child requires its parent.');
        } catch (\LogicException $exception) {
            static::assertStringContainsString('leaves its parent', $exception->getMessage());
            static::assertNotFalse($parent->stored($order));
            static::assertFalse($child->stored($line));
        } finally {
            $parent->storage->rollBack();
        }
    }

    public function test_replacing_a_referenced_parent_is_rejected_before_writing(): void
    {
        [$parent, , $orders, , $flush] = $this->tables('memory');
        $order = new MappedKey(['tenant' => 'one', 'id' => 'old']);
        $parent->seed([...$order->values, 'status' => 'old']);
        $orders->withKey($order)->delete()->insert()->setColumn('status', 'new');

        $this->expectExceptionMessage('Cannot replace a row of referenced table');
        $parent->flush($flush);
    }

    public function test_coordinated_flush_requires_an_active_transaction(): void
    {
        [$parent, , $orders, , $flush] = $this->tables('memory');
        $order = new MappedKey(['tenant' => 'one', 'id' => 'order']);
        $orders->withKey($order)->insert();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('active transaction');
        try {
            $flush->flush();
        } finally {
            static::assertFalse($parent->stored($order));
            static::assertTrue($flush->hasPendingChanges());
        }
    }

    public function test_assembly_rejects_row_sets_bound_to_a_different_adapter(): void
    {
        $parent = $this->fixture();
        $child = $this->fixture(storage: $parent->storage);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('same mapped table adapter');
        new MappedTableUnitOfWork([
            $parent->tableName => $parent->rows,
            $child->tableName => $child->rows,
        ], $parent->adapter);
    }

    public function test_cyclic_owner_dependencies_are_rejected_during_assembly(): void
    {
        $parent = $this->fixture('memory', ['owner', 'id']);
        $child = $this->fixture('memory', ['owner', 'id'], storage: $parent->storage, adapter: $parent->adapter);
        $columns = ['owner' => new MappedColumn('string'), 'id' => new MappedColumn('string')];
        $orders = $parent->adapter->createDefined(new MappedRowSetMapping(
            $parent->tableName,
            $columns,
            ['owner', 'id'],
            owner: new MappedRowSetOwner(FlushSchema::LINES, ['owner' => 'id']),
        ));
        $lines = $child->adapter->createDefined(new MappedRowSetMapping(
            $child->tableName,
            $columns,
            ['owner', 'id'],
            owner: new MappedRowSetOwner(FlushSchema::ORDERS, ['owner' => 'id']),
        ));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cyclic mapped row set ownership');
        new MappedTableUnitOfWork([
            FlushSchema::ORDERS => $orders,
            FlushSchema::LINES => $lines,
        ], $parent->adapter);
    }

    /** @return array{RowSetFixture, RowSetFixture, MappedRowSet, MappedRowSet, MappedTableUnitOfWork} */
    private function tables(string $backend): array
    {
        $parent = $this->fixture($backend, ['tenant', 'id']);
        $child = $this->fixture(
            $backend,
            ['order_tenant', 'order_id', 'id'],
            storage: $parent->storage,
            adapter: $parent->adapter,
        );
        if ($parent->storage instanceof Connection) {
            $quote = $parent->storage->quoteSingleIdentifier(...);
            $parent->storage->executeStatement(sprintf(
                'ALTER TABLE %s ADD FOREIGN KEY (order_tenant, order_id) REFERENCES %s (tenant, id)',
                $quote($child->tableName),
                $quote($parent->tableName),
            ));
        }

        return [$parent, $child, ...$this->coordinate($parent, $child)];
    }

    /** @return array{MappedRowSet, MappedRowSet, MappedTableUnitOfWork} */
    private function coordinate(RowSetFixture $parent, RowSetFixture $child): array
    {
        $schema = FlushSchema::definition($parent->tableName, $child->tableName);
        $orders = new SchemaMappedRowSet(FlushSchema::ORDERS)->compileFor($parent->adapter, $schema)();
        $lines = new SchemaMappedRowSet(FlushSchema::LINES, owner: 'owner')->compileFor($child->adapter, $schema)();

        $flush = new MappedTableUnitOfWork([
            FlushSchema::LINES => $lines,
            FlushSchema::ORDERS => $orders,
        ], $parent->adapter);

        return [$orders, $lines, $flush];
    }

    private function transactions(Connection|InMemoryRowDatabase $storage): TransactionManager
    {
        if ($storage instanceof Connection) {
            return new DbalTransactionManager($storage);
        }

        return new class($storage) implements TransactionManager {
            public function __construct(
                private readonly InMemoryRowDatabase $storage,
            ) {}

            public function transactional(callable $callback): mixed
            {
                $this->storage->beginTransaction();
                try {
                    $result = $callback();
                    $this->storage->commit();

                    return $result;
                } catch (\Throwable $exception) {
                    $this->storage->rollBack();

                    throw $exception;
                }
            }
        };
    }
}

final class FlushSchema
{
    public const string ORDERS = 'orders';
    public const string LINES = 'lines';

    public static function definition(string $orders, string $lines): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                self::ORDERS,
                $orders,
                [
                    new ColumnDefinition('tenant', type: ColumnType::Text),
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('status', type: ColumnType::Text, nullable: true),
                ],
                primaryKey: ['tenant', 'id'],
            ),
            new TableDefinition(
                self::LINES,
                $lines,
                [
                    new ColumnDefinition('order_tenant', type: ColumnType::Text),
                    new ColumnDefinition('order_id', type: ColumnType::Text),
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('status', type: ColumnType::Text, nullable: true),
                ],
                primaryKey: ['order_tenant', 'order_id', 'id'],
                foreignKeys: ['owner' => new ForeignKeyDefinition(self::ORDERS, [
                    'order_tenant' => 'tenant',
                    'order_id' => 'id',
                ])],
            ),
        ]);
    }
}
