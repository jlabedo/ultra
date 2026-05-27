<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\MappedOwnedRowSet;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedOwnedRowSetAdapter;
use Ultra\Testing\PostgresTestSupport;

/**
 * @internal
 */
#[CoversClass(PostgresMappedOwnedRowSetAdapter::class)]
final class PostgresMappedOwnedRowSetAdapterTest extends TestCase
{
    private static function asDateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    private function withTable(callable $callback): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres mapped-owned-row-set integration tests.');
        }

        $connection = PostgresTestSupport::sharedConnection($dsn);
        $tableName = 'ultra_owned_set_'.bin2hex(random_bytes(4));

        try {
            $connection->executeStatement(sprintf(
                'CREATE TABLE %s (
                    subject_id TEXT NOT NULL,
                    capability_id TEXT NOT NULL,
                    eligible_from DATE NOT NULL,
                    eligible_to DATE NULL,
                    PRIMARY KEY (subject_id, capability_id, eligible_from)
                )',
                $connection->quoteSingleIdentifier($tableName),
            ));

            $adapter = new PostgresMappedOwnedRowSetAdapter(
                $connection,
                $tableName,
                ['subject_id', 'capability_id'],
                ['eligible_from', 'eligible_to'],
                ['eligible_from' => PostgresMappedColumnType::date(), 'eligible_to' => PostgresMappedColumnType::date()],
            );

            $callback($connection, new MappedOwnedRowSet($adapter), $tableName);
        } finally {
            PostgresTestSupport::resetSharedConnection($connection);
            $connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s',
                $connection->quoteSingleIdentifier($tableName),
            ));
        }
    }

    public function test_replace_from_empty_inserts_rows(): void
    {
        $this->withTable(function (Connection $connection, MappedOwnedRowSet $set, string $tableName): void {
            $set->withParent(['subject_id' => 's-1', 'capability_id' => 'c-1'])
                ->replace([
                    ['eligible_from' => '2026-01-01', 'eligible_to' => '2026-06-01'],
                    ['eligible_from' => '2026-07-01', 'eligible_to' => null],
                ]);
            $set->flush();

            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT eligible_from, eligible_to FROM %s ORDER BY eligible_from', $connection->quoteSingleIdentifier($tableName)),
            );
            self::assertCount(2, $rows);
            self::assertSame('2026-01-01', substr((string) $rows[0]['eligible_from'], 0, 10));
            self::assertNull($rows[1]['eligible_to']);
        });
    }

    public function test_replace_overwrites_prior_set_for_same_parent_only(): void
    {
        $this->withTable(function (Connection $connection, MappedOwnedRowSet $set, string $tableName): void {
            $a = ['subject_id' => 's-1', 'capability_id' => 'c-1'];
            $b = ['subject_id' => 's-2', 'capability_id' => 'c-1'];

            $set->withParent($a)->replace([
                ['eligible_from' => '2026-01-01', 'eligible_to' => null],
                ['eligible_from' => '2026-02-01', 'eligible_to' => null],
            ]);
            $set->withParent($b)->replace([
                ['eligible_from' => '2026-03-01', 'eligible_to' => null],
            ]);
            $set->flush();

            $set->withParent($a)->replace([
                ['eligible_from' => '2026-12-12', 'eligible_to' => null],
            ]);
            $set->flush();

            self::assertSame(1, (int) $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE subject_id = ?', $connection->quoteSingleIdentifier($tableName)),
                ['s-1'],
            ));
            self::assertSame(1, (int) $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE subject_id = ?', $connection->quoteSingleIdentifier($tableName)),
                ['s-2'],
            ));
        });
    }

    public function test_replace_with_empty_deletes_owned_set(): void
    {
        $this->withTable(function (Connection $connection, MappedOwnedRowSet $set, string $tableName): void {
            $parent = ['subject_id' => 's-1', 'capability_id' => 'c-1'];

            $set->withParent($parent)->replace([
                ['eligible_from' => '2026-01-01', 'eligible_to' => null],
            ]);
            $set->flush();

            $set->withParent($parent)->replace([]);
            $set->flush();

            self::assertSame(0, (int) $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s', $connection->quoteSingleIdentifier($tableName)),
            ));
        });
    }

    public function test_delete_parents_matching_removes_by_partial_key(): void
    {
        $this->withTable(function (Connection $connection, MappedOwnedRowSet $set, string $tableName): void {
            $set->withParent(['subject_id' => 's-1', 'capability_id' => 'c-1'])
                ->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);
            $set->withParent(['subject_id' => 's-2', 'capability_id' => 'c-1'])
                ->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);
            $set->withParent(['subject_id' => 's-1', 'capability_id' => 'c-2'])
                ->replace([['eligible_from' => '2026-01-01', 'eligible_to' => null]]);
            $set->flush();

            $set->deleteParentsMatching(['capability_id' => 'c-1']);
            $set->flush();

            self::assertSame(0, (int) $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE capability_id = ?', $connection->quoteSingleIdentifier($tableName)),
                ['c-1'],
            ));
            self::assertSame(1, (int) $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE capability_id = ?', $connection->quoteSingleIdentifier($tableName)),
                ['c-2'],
            ));
        });
    }

    public function test_delete_parents_matching_rejects_non_parent_columns(): void
    {
        $this->withTable(function (Connection $connection, MappedOwnedRowSet $set, string $tableName): void {
            $this->expectException(\InvalidArgumentException::class);
            $set->deleteParentsMatching(['eligible_from' => '2026-01-01']);
            $set->flush();
        });
    }

    public function test_rows_for_parent_returns_decoded_dates(): void
    {
        $this->withTable(function (Connection $connection, MappedOwnedRowSet $set, string $tableName): void {
            $parent = ['subject_id' => 's-1', 'capability_id' => 'c-1'];
            $set->withParent($parent)->replace([
                ['eligible_from' => new \DateTimeImmutable('2026-01-01'), 'eligible_to' => new \DateTimeImmutable('2026-06-01')],
            ]);
            $set->flush();

            $rows = $set->rowsForParent($parent);
            self::assertCount(1, $rows);
            self::assertSame('2026-01-01', self::asDateString($rows[0]['eligible_from']));
            self::assertSame('2026-06-01', self::asDateString($rows[0]['eligible_to']));
        });
    }
}
