<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\MappedTable;
use Ultra\MappedTable\PostgresMappedTableAdapter;



abstract class PostgresMappedTableTestCase extends TestCase
{
    protected function withMappedTable(callable $callback): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres mapped-table integration tests.');
        }

        $connection = \Ultra\Testing\PostgresTestSupport::sharedConnection($dsn);
        $tableName = 'ultra_mapped_table_'.bin2hex(random_bytes(4));

        try {
            $connection->executeStatement(sprintf(
                'CREATE TABLE %s (
                    id TEXT PRIMARY KEY,
                    status TEXT NULL,
                    note TEXT NULL,
                    version_a TEXT NULL,
                    version_b TEXT NULL
                )',
                $connection->quoteSingleIdentifier($tableName),
            ));

            $callback($connection, new MappedTable(new PostgresMappedTableAdapter($connection), $tableName), $tableName);
        } finally {
            \Ultra\Testing\PostgresTestSupport::resetSharedConnection($connection);

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
            sprintf('SELECT id, status, note, version_a, version_b FROM %s WHERE id = ?', $connection->quoteSingleIdentifier($tableName)),
            [$id],
        );
    }
}
