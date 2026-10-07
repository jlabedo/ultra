<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\EventStore;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;

final readonly class FacultyEventStoreFactory
{
    public const DEFAULT_TABLE_PREFIX = 'faculty_';

    public function __construct(
        private ?Connection $connection = null,
        private string $tablePrefix = self::DEFAULT_TABLE_PREFIX,
    ) {}

    public function create(): EventStore
    {
        if (!$this->shouldUsePostgres()) {
            return new InMemoryEventStore();
        }

        return new PostgresEventStoreHStore($this->connection, $this->tablePrefix);
    }

    private function shouldUsePostgres(): bool
    {
        if (null === $this->connection) {
            return false;
        }

        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }
}
