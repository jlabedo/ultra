<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\LockStrategy;

use Doctrine\DBAL\Connection;

interface LockStrategy
{
    public function name(): string;

    /**
     * @return list<string>
     */
    public function createStatements(string $tablePrefix): array;

    /**
     * @return list<string>
     */
    public function dropStatements(string $tablePrefix): array;

    /**
     * @param list<string> $readKeys
     * @param list<string> $writeKeys
     */
    public function acquire(Connection $connection, string $tablePrefix, array $readKeys, array $writeKeys): void;
}
