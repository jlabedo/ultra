<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/**
 * @internal
 */
#[CoversClass(PostgresMappedRowSetAdapter::class)]
#[CoversClass(PostgresMappedColumnType::class)]
final class PostgresMappedRowSetAdapterTest extends PostgresMappedRowSetTestCase
{
    public function test_row_returns_projected_columns(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            self::assertSame([
                'status' => 'pending',
                'version_b' => 'B1',
            ], $table->row('row-1', ['status', 'version_b']));
        });
    }

    public function test_row_without_projection_returns_full_row(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            self::assertSame([
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
                'tags' => null,
                'payload' => null,
                'expires_on' => null,
            ], $table->row('row-1'));
        });
    }

    public function test_rows_returns_projected_rows(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-2',
                'status' => 'active',
                'note' => 'published',
            ]);
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
            ]);

            $rows = $table->rows(['status', 'note']);
            self::assertSame(['row-1', 'row-2'], array_map(static fn ($row): int|string => $row->key->value('id'), $rows));
            self::assertSame([
                [
                    'status' => 'pending',
                    'note' => 'draft',
                ],
                [
                    'status' => 'active',
                    'note' => 'published',
                ],
            ], array_map(static fn ($row): array => $row->columns, $rows));
        });
    }

    public function test_jsonb_column_type_round_trips_arrays(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $payload = [
                'anyOf' => [
                    ['documentType' => 'passport'],
                    ['allOf' => [['documentType' => 'driver_license']]],
                ],
            ];

            $table->withKey('row-1')->upsert()->setColumn('payload', $payload);
            $this->flush($connection, $table);

            self::assertSame(['payload' => $payload], $table->row('row-1', ['payload']));
        }, [
            'payload' => PostgresMappedColumnType::jsonb(),
        ]);
    }

    public function test_date_column_type_round_trips_iso_dates(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')->upsert()->setColumn('expires_on', '2026-05-25');
            $this->flush($connection, $table);

            self::assertSame(['expires_on' => '2026-05-25'], $table->row('row-1', ['expires_on']));
            self::assertSame('date', $connection->fetchOne(
                'SELECT pg_typeof(expires_on)::text FROM '.$connection->quoteSingleIdentifier($tableName).' WHERE id = ?',
                ['row-1'],
            ));
        }, [
            'expires_on' => PostgresMappedColumnType::date(),
        ]);
    }

    public function test_expect_same_row_ids_succeeds_when_ids_match(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'row-2']);
            $this->insertRow($connection, $tableName, ['id' => 'row-1']);

            $table->expectSameRowKeys(['row-1', 'row-2']);
            $this->flush($connection, $table);

            self::assertSame(['row-1', 'row-2'], $connection->fetchFirstColumn(sprintf(
                'SELECT id FROM %s ORDER BY id',
                $connection->quoteSingleIdentifier($tableName),
            )));
        });
    }

    public function test_expect_same_row_ids_throws_when_row_was_inserted(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'row-1']);

            $table->expectSameRowKeys(['row-1']);
            $this->insertRow($connection, $tableName, ['id' => 'row-2']);

            $this->expectException(ConcurrencyException::class);
            $this->flush($connection, $table);
        });
    }

    public function test_expect_same_row_ids_throws_when_row_was_deleted(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'row-1']);
            $this->insertRow($connection, $tableName, ['id' => 'row-2']);

            $table->expectSameRowKeys(['row-1', 'row-2']);
            $connection->delete($tableName, ['id' => 'row-2']);

            $this->expectException(ConcurrencyException::class);
            $this->flush($connection, $table);
        });
    }

    public function test_row_level_expectations_still_work_with_row_set_expectations(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'version_a' => 'A1',
            ]);

            $table->expectSameRowKeys(['row-1']);
            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('status', 'active')
            ;

            $this->flush($connection, $table);

            self::assertSame('active', $this->fetchRow($connection, $tableName, 'row-1')['status']);
        });
    }

    public function test_expect_absent_turns_later_column_writes_into_insert(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->expectAbsent()
                ->setColumn('status', 'pending')
                ->setColumn('note', 'draft')
            ;

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

    public function test_flush_deletes_row(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->delete()
            ;

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

    public function test_flush_throws_when_delete_expected_columns_do_not_match(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A2')
                ->delete()
            ;

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

    public function test_flush_merges_delete_with_prior_buffered_updates_for_the_same_row(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('status', 'active')
            ;

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->delete()
            ;

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

    public function test_delete_cancels_pending_insert_for_the_same_row(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->insert()
                ->setColumn('status', 'pending')
            ;

            $table->withKey('row-1')
                ->delete()
            ;

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

    public function test_insert_after_delete_replaces_row_and_keeps_its_initial_expectation(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->delete()
            ;

            $table->withKey('row-1')
                ->insert()
                ->setColumn('status', 'recreated')
                ->setColumn('note', 'fresh')
                ->setColumn('version_a', 'A2')
            ;

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

    public function test_insert_after_delete_fails_when_the_initial_expectation_is_stale(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A2',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->delete()
            ;

            $table->withKey('row-1')
                ->insert()
                ->setColumn('status', 'recreated')
            ;

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

    public function test_delete_rejects_subsequent_column_updates(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->expectException(ConcurrencyException::class);

            $table->withKey('row-1')
                ->delete()
                ->setColumn('status', 'pending')
            ;
        });
    }

    public function test_flush_upserts_row_by_inserting_when_absent(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->upsert()
                ->setColumn('status', 'pending')
                ->setColumn('note', 'draft')
                ->setColumn('version_a', 'A1')
            ;

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

    public function test_flush_upserts_row_by_updating_when_present(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'existing',
                'note' => 'current',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->upsert()
                ->setColumn('status', 'updated')
                ->setColumn('note', 'fresh')
            ;

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

    public function test_upsert_works_when_called_after_set_column(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->setColumn('status', 'pending')
                ->setColumn('note', 'draft')
                ->upsert()
            ;

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

    public function test_upsert_after_delete_replaces_row_and_keeps_its_initial_expectation(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->delete()
            ;

            $table->withKey('row-1')
                ->upsert()
                ->setColumn('status', 'upserted')
                ->setColumn('version_a', 'A2')
            ;

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

    public function test_flush_inserts_row(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->insert()
                ->setColumn('status', 'pending')
                ->setColumn('note', 'draft')
                ->setColumn('version_a', 'A1')
            ;

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

    public function test_flush_merges_insert_with_other_buffered_changes_for_the_same_row(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->setColumn('status', 'pending')
            ;

            $table->withKey('row-1')
                ->insert()
                ->setColumn('note', 'draft')
                ->setColumn('version_a', 'A1')
            ;

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

    public function test_flush_throws_when_insert_targets_an_existing_row(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'existing',
                'note' => 'current',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->insert()
                ->setColumn('status', 'new')
            ;

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

    public function test_insert_rejects_expected_column_values(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->expectException(ConcurrencyException::class);

            $table->withKey('row-1')
                ->insert()
                ->expectColumnValue('version_a', 'A1')
            ;
        });
    }

    public function test_flush_updates_row_when_expected_columns_match(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->expectColumnValue('version_b', 'B1')
                ->setColumn('status', 'active')
                ->setColumn('note', 'published')
            ;

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

    public function test_flush_supports_null_expected_values(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => null,
                'version_a' => null,
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('note', null)
                ->expectColumnValue('version_a', null)
                ->setColumn('status', 'active')
            ;

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

    public function test_flush_throws_when_expected_columns_do_not_match(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'active',
                'note' => 'current',
                'version_a' => 'A2',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('note', 'next')
            ;

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

    public function test_flush_merges_operations_targeting_the_same_row(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('version_a', 'A2')
            ;

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('note', 'published')
            ;

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

    public function test_conflicting_expectations_for_the_same_row_throw_immediately(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('status', 'active')
            ;

            $this->expectException(ConcurrencyException::class);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A2')
            ;
        });
    }

    public function test_clear_drops_buffered_updates(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, [
                'id' => 'row-1',
                'status' => 'pending',
                'note' => 'draft',
                'version_a' => 'A1',
                'version_b' => 'B1',
            ]);

            $table->withKey('row-1')
                ->expectColumnValue('version_a', 'A1')
                ->setColumn('status', 'active')
            ;

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

    public function test_insert_round_trips_text_array_columns(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->insert()
                ->setColumn('status', 'pending')
                ->setColumn('tags', ['alpha', 'beta'])
            ;

            $this->flush($connection, $table);

            self::assertSame([
                'status' => 'pending',
                'tags' => ['alpha', 'beta'],
            ], $table->row('row-1', ['status', 'tags']));

            self::assertSame([
                'id' => 'row-1',
                'status' => 'pending',
                'note' => null,
                'version_a' => null,
                'version_b' => null,
                'tags' => ['alpha', 'beta'],
            ], $this->fetchRowWithTags($connection, $tableName, 'row-1'));
        }, [
            'tags' => PostgresMappedColumnType::textArray(),
        ]);
    }

    public function test_update_supports_empty_array_columns_and_optimistic_expectations(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->insert()
                ->setColumn('status', 'pending')
                ->setColumn('tags', ['alpha'])
            ;
            $this->flush($connection, $table);
            $table->clear();

            $table->withKey('row-1')
                ->expectColumnValue('tags', ['alpha'])
                ->setColumn('tags', [])
            ;

            $this->flush($connection, $table);

            self::assertSame([], $this->fetchRowWithTags($connection, $tableName, 'row-1')['tags']);
        }, [
            'tags' => PostgresMappedColumnType::textArray(),
        ]);
    }

    public function test_update_rejects_stale_array_expectations(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->insert()
                ->setColumn('status', 'pending')
                ->setColumn('tags', ['alpha'])
            ;
            $this->flush($connection, $table);
            $table->clear();

            $table->withKey('row-1')
                ->expectColumnValue('tags', ['beta'])
                ->setColumn('status', 'active')
            ;

            $this->expectException(ConcurrencyException::class);

            try {
                $this->flush($connection, $table);
            } finally {
                self::assertSame(['alpha'], $this->fetchRowWithTags($connection, $tableName, 'row-1')['tags']);
            }
        }, [
            'tags' => PostgresMappedColumnType::textArray(),
        ]);
    }

    public function test_batch_upsert_supports_array_columns(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $table->withKey('row-1')
                ->upsert()
                ->setColumn('status', 'pending')
                ->setColumn('tags', ['alpha'])
            ;

            $table->withKey('row-2')
                ->upsert()
                ->setColumn('status', 'active')
                ->setColumn('tags', ['beta', 'gamma'])
            ;

            $this->flush($connection, $table);

            self::assertSame(['alpha'], $this->fetchRowWithTags($connection, $tableName, 'row-1')['tags']);
            self::assertSame(['beta', 'gamma'], $this->fetchRowWithTags($connection, $tableName, 'row-2')['tags']);
        }, [
            'tags' => PostgresMappedColumnType::textArray(),
        ]);
    }

    public function test_composite_key_round_trips_through_postgres(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $_, string $tableName): void {
            $compositeTable = $tableName.'_composite';
            $connection->executeStatement(sprintf(
                'CREATE TABLE %s (identity_id TEXT NOT NULL, terms_id TEXT NOT NULL, accepted_at TEXT NOT NULL, PRIMARY KEY (identity_id, terms_id))',
                $connection->quoteSingleIdentifier($compositeTable),
            ));

            try {
                $table = new MappedRowSet(new PostgresMappedRowSetAdapter(
                    $connection,
                    $compositeTable,
                    ['identity_id', 'terms_id'],
                ));
                $key = new MappedKey(['identity_id' => 'identity-1', 'terms_id' => 'terms-2026']);
                $table->withKey($key)->insert()->setColumn('accepted_at', 'now');
                $this->flush($connection, $table);

                self::assertSame(['accepted_at' => 'now'], $table->row($key, ['accepted_at']));
                $rows = $table->rows(['accepted_at']);
                self::assertCount(1, $rows);
                self::assertSame($key->values, $rows[0]->key->values);
            } finally {
                $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $connection->quoteSingleIdentifier($compositeTable)));
            }
        });
    }

    private function flush(Connection $connection, MappedRowSet $table): void
    {
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
    }
}
