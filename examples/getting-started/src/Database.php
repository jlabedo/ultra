<?php

declare(strict_types=1);

namespace GettingStarted;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final class Database
{
    public static function connect(): Connection
    {
        $dsn = getenv('DATABASE_URL');
        if (false === $dsn || '' === $dsn) {
            throw new \RuntimeException('Set DATABASE_URL to a PostgreSQL DSN before running this example.');
        }

        return DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($dsn));
    }
}
