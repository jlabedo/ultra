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
    public const TABLE_PREFIX = 'faculty_';

    public function __construct(
        private EventTopologyProvider $topologyProvider,
        private ?Connection $connection = null,
    ) {}

    public function create(): EventStore
    {
        if (! $this->shouldUsePostgres()) {
            return new InMemoryEventStore($this->topologyProvider);
        }

        if (null === $this->connection) {
            throw new \LogicException('Doctrine DBAL connection is not available.');
        }

        return new PostgresEventStore(
            $this->connection,
            $this->topologyProvider,
            self::TABLE_PREFIX,
        );
    }

    private function shouldUsePostgres(): bool
    {
        $databaseUrl = strtolower(trim((string) ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '')));

        return str_starts_with($databaseUrl, 'pgsql://') || str_starts_with($databaseUrl, 'postgresql://');
    }
}
