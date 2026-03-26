<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\EventStore;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventTopologyProvider;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore_Postgres\PostgresEventStore;

final readonly class FacultyEventStoreFactory
{
    public const DEFAULT_TABLE_PREFIX = 'faculty_';

    public function __construct(
        private EventTopologyProvider $topologyProvider,
        private ?Connection $connection = null,
        private string $tablePrefix = self::DEFAULT_TABLE_PREFIX,
    ) {}

    public function create(): EventStore
    {
        if (! $this->shouldUsePostgres()) {
            return new InMemoryEventStore($this->topologyProvider);
        }

        return new PostgresEventStore(
            $this->connection,
            $this->topologyProvider,
            $this->tablePrefix,
        );
    }

    private function shouldUsePostgres(): bool
    {
        if (null === $this->connection) {
            return false;
        }

        return $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
    }
}
