<?php

declare(strict_types=1);

namespace Ultra\MappedTable_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable_Postgres\PostgresMappedTable;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final class PostgresMappedTableTest extends PostgresMappedTableTestCase
{
    public function testFlushUpdatesRowWhenExpectedColumnsMatch(): void
    {
        $this->withMappedTable(function (Connection $connection, PostgresMappedTable $table, string $tableName): void {
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
        $this->withMappedTable(function (Connection $connection, PostgresMappedTable $table, string $tableName): void {
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
        $this->withMappedTable(function (Connection $connection, PostgresMappedTable $table, string $tableName): void {
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
        $this->withMappedTable(function (Connection $connection, PostgresMappedTable $table, string $tableName): void {
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
        $this->withMappedTable(function (Connection $connection, PostgresMappedTable $table, string $tableName): void {
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
        $this->withMappedTable(function (Connection $connection, PostgresMappedTable $table, string $tableName): void {
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
