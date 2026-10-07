<?php

declare(strict_types=1);

namespace IamExample\Infrastructure;

use Doctrine\DBAL\Connection;

final class Schema
{
    public static function install(Connection $connection): void
    {
        foreach (explode(';', file_get_contents(__DIR__ . '/schema.sql')) as $statement) {
            if ('' === trim($statement)) {
                continue;
            }

            $connection->executeStatement($statement);
        }
    }
}
