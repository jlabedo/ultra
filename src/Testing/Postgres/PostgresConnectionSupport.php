<?php

declare(strict_types=1);

namespace Ultra\Testing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/** PostgreSQL sessions shared by test fixtures, independently of their storage model. */
final class PostgresConnectionSupport
{
    /** @var array<string, array<string, Connection>> */
    private static array $connections = [];

    public static function connection(string $dsn): Connection
    {
        return DriverManager::getConnection(new DsnParser([
            'postgres' => 'pgsql',
            'postgresql' => 'pgsql',
        ])->parse($dsn));
    }

    /** Callers reset borrowed connections; shutdown closes each named session. */
    public static function sharedConnection(string $dsn, string $name = 'default'): Connection
    {
        if (!isset(self::$connections[$dsn][$name])) {
            self::$connections[$dsn][$name] = self::connection($dsn);

            register_shutdown_function(static function () use ($dsn, $name): void {
                self::$connections[$dsn][$name]->close();
            });
        }

        return self::$connections[$dsn][$name];
    }

    /** Rolls back pending work and releases session locks; fixtures restore their own session settings. */
    public static function resetSharedConnection(Connection $connection): void
    {
        while ($connection->getTransactionNestingLevel() > 0) {
            $connection->rollBack();
        }

        $connection->executeStatement('SELECT pg_advisory_unlock_all()');
    }
}
