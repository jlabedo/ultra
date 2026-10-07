<?php

declare(strict_types=1);

namespace Ultra\Testing;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;

abstract class PostgresRowSetTestCase extends TestCase
{
    /**
     * @param array<string, PostgresMappedColumnType> $columnTypes
     */
    protected function withMappedRowSet(callable $callback, array $columnTypes = []): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres mapped-row-set integration tests.');
        }

        $connection = PostgresConnectionSupport::sharedConnection($dsn);
        $tableName = 'ultra_mapped_row_set_' . bin2hex(random_bytes(4));

        try {
            $connection->executeStatement(sprintf('CREATE TABLE %s (
                    id TEXT PRIMARY KEY,
                    status TEXT NULL,
                    note TEXT NULL,
                    version_a TEXT NULL,
                    version_b TEXT NULL,
                    tags TEXT[] NULL,
                    payload JSONB NULL,
                    expires_on DATE NULL
                )', $connection->quoteSingleIdentifier($tableName)));

            $callback(
                $connection,
                new MappedRowSet(new PostgresMappedRowSetAdapter($connection, $tableName, columnTypes: $columnTypes)),
                $tableName,
            );
        } finally {
            PostgresConnectionSupport::resetSharedConnection($connection);

            $connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s',
                $connection->quoteSingleIdentifier($tableName),
            ));
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function insertRow(Connection $connection, string $tableName, array $row): void
    {
        $connection->insert($tableName, $row);
    }

    /**
     * @return array<string, mixed>|false
     */
    protected function fetchRow(Connection $connection, string $tableName, string $id): array|false
    {
        return $connection->fetchAssociative(
            sprintf(
                'SELECT id, status, note, version_a, version_b FROM %s WHERE id = ?',
                $connection->quoteSingleIdentifier($tableName),
            ),
            [$id],
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    protected function fetchRowWithTags(Connection $connection, string $tableName, string $id): array|false
    {
        $row = $connection->fetchAssociative(
            sprintf(
                'SELECT id, status, note, version_a, version_b, tags FROM %s WHERE id = ?',
                $connection->quoteSingleIdentifier($tableName),
            ),
            [$id],
        );

        if (false === $row || null === $row['tags']) {
            return $row;
        }

        $row['tags'] = PostgresMappedColumnType::textArray()->decode($row['tags']);

        return $row;
    }
}
