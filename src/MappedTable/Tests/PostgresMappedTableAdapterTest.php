<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\MappedTable;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final class PostgresMappedTableAdapterTest extends PostgresMappedTableTestCase
{
    public function testFlushDeletesRow(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->delete();

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertFalse($this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testFlushThrowsWhenDeleteExpectedColumnsDoNotMatch(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A2')
                ->delete();

            $connection->beginTransaction();

            try {
                $table->flush();
                self::fail('Expected ConcurrencyException to be thrown.');
            } catch (ConcurrencyException $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                self::assertNotFalse($this->fetchRow($connection, $tableName, 'row-1'));
            }
        });
    }

    public function testFlushMergesDeleteWithPriorBufferedUpdatesForTheSameRow(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('status', 'active');

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->delete();

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertFalse($this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testDeleteCancelsPendingInsertForTheSameRow(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $table->withId('row-1')
                ->insert()
                ->setColumn('status', 'pending');

            $table->withId('row-1')
                ->delete();

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertFalse($this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testInsertAfterDeleteReplacesRowAndKeepsItsInitialExpectation(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->delete();

            $table->withId('row-1')
                ->insert()
                ->setColumn('status', 'recreated')
                ->setColumn('note', 'fresh')
                ->setColumn('version_a', 'A2');

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame([
                'id' => 'row-1',
                'status' => 'recreated',
                'note' => 'fresh',
                'version_a' => 'A2',
                'version_b' => null,
            ], $this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testInsertAfterDeleteFailsWhenTheInitialExpectationIsStale(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A2',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->delete();

            $table->withId('row-1')
                ->insert()
                ->setColumn('status', 'recreated');

            $connection->beginTransaction();

            try {
                $table->flush();
                self::fail('Expected ConcurrencyException to be thrown.');
            } catch (ConcurrencyException $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                self::assertSame([
                    'id' => 'row-1',
                    'status' => 'pending',
                    'note' => 'draft',
                    'version_a' => 'A2',
                    'version_b' => 'B1',
                ], $this->fetchRow($connection, $tableName, 'row-1'));
            }
        });
    }

    public function testDeleteRejectsSubsequentColumnUpdates(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->expectException(ConcurrencyException::class);

            $table->withId('row-1')
                ->delete()
                ->setColumn('status', 'pending');
        });
    }

    public function testFlushUpsertsRowByInsertingWhenAbsent(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $table->withId('row-1')
                ->upsert()
                ->setColumn('status', 'pending')
                ->setColumn('note', 'draft')
                ->setColumn('version_a', 'A1');

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame([
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => null,
            ], $this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testFlushUpsertsRowByUpdatingWhenPresent(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'existing',
                'note' => 'current',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->upsert()
                ->setColumn('status', 'updated')
                ->setColumn('note', 'fresh');

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame([
                'id' => 'row-1',
                'status' => 'updated',
                'note' => 'fresh',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ], $this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testUpsertWorksWhenCalledAfterSetColumn(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $table->withId('row-1')
                ->setColumn('status', 'pending')
                ->setColumn('note', 'draft')
                ->upsert();

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame([
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => null,
                'version_b' => null,
            ], $this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testUpsertAfterDeleteReplacesRowAndKeepsItsInitialExpectation(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->delete();

            $table->withId('row-1')
                ->upsert()
                ->setColumn('status', 'upserted')
                ->setColumn('version_a', 'A2');

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame([
                'id' => 'row-1',
                'status' => 'upserted',
                'note' => null,
                'version_a' => 'A2',
                'version_b' => null,
            ], $this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testFlushInsertsRow(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $table->withId('row-1')
                ->insert()
                ->setColumn('status', 'pending')
                ->setColumn('note', 'draft')
                ->setColumn('version_a', 'A1');

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame([
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => null,
            ], $this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testFlushMergesInsertWithOtherBufferedChangesForTheSameRow(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $table->withId('row-1')
                ->setColumn('status', 'pending');

            $table->withId('row-1')
                ->insert()
                ->setColumn('note', 'draft')
                ->setColumn('version_a', 'A1');

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame([
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => null,
            ], $this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testFlushThrowsWhenInsertTargetsAnExistingRow(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'existing',
                'note' => 'current',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->insert()
                ->setColumn('status', 'new');

            $connection->beginTransaction();

            try {
                $table->flush();
                self::fail('Expected ConcurrencyException to be thrown.');
            } catch (ConcurrencyException $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                self::assertSame('existing', $this->fetchRow($connection, $tableName, 'row-1')['status']);
            }
        });
    }

    public function testInsertRejectsExpectedColumnValues(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->expectException(ConcurrencyException::class);

            $table->withId('row-1')
                ->insert()
                ->expectColumnValue('version_a', 'A1');
        });
    }

    public function testFlushUpdatesRowWhenExpectedColumnsMatch(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->expectColumnValue('version_b', 'B1')
                ->setColumn('status', 'active')
                ->setColumn('note', 'published');

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame([
                'id' => 'row-1',
                'status' => 'active',
                'note' => 'published',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ], $this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testFlushSupportsNullExpectedValues(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => null,
                'version_a' => null,
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('note', null)
                ->expectColumnValue('version_a', null)
                ->setColumn('status', 'active');

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame('active', $this->fetchRow($connection, $tableName, 'row-1')['status']);
        });
    }

    public function testFlushThrowsWhenExpectedColumnsDoNotMatch(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'active',
                'note' => 'current',
                'version_a' => 'A2',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('note', 'next');

            $connection->beginTransaction();

            try {
                $table->flush();
                self::fail('Expected ConcurrencyException to be thrown.');
            } catch (ConcurrencyException $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                self::assertSame(
                    'current',
                    $this->fetchRow($connection, $tableName, 'row-1')['note'],
                );
            }
        });
    }

    public function testFlushMergesOperationsTargetingTheSameRow(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('version_a', 'A2');

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('note', 'published');

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame([
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'published',
                'version_a' => 'A2',
                'version_b' => 'B1',
            ], $this->fetchRow($connection, $tableName, 'row-1'));
        });
    }

    public function testConflictingExpectationsForTheSameRowThrowImmediately(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('status', 'active');

            $this->expectException(ConcurrencyException::class);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A2');
        });
    }

    public function testClearDropsBufferedUpdates(): void
    {
        $this->withMappedTable(function (Connection $connection, MappedTable $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withId('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('status', 'active');

            $table->clear();

            $connection->beginTransaction();
            try {
                $table->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }

            self::assertSame('pending', $this->fetchRow($connection, $tableName, 'row-1')['status']);
        });
    }
}
