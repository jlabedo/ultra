<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\LockStrategy;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;

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
     * @param list<StreamQuery> $readQueries
     * @param list<Event>       $writeEvents
     */
    public function acquire(Connection $connection, string $tablePrefix, array $readQueries, array $writeEvents): void;
}
