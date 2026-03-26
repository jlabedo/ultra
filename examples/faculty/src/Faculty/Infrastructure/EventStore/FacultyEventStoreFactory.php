<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\EventStore;

use Doctrine\DBAL\DriverManager;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventTopologyProvider;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore_Postgres\PostgresEventStore;

final readonly class FacultyEventStoreFactory
{
    public const TABLE_PREFIX = 'faculty_';

    public function __construct(
        private EventTopologyProvider $topologyProvider,
    ) {}

    public function create(): EventStore
    {
        $databaseUrl = trim((string) ($_ENV['FACULTY_DATABASE_URL'] ?? $_SERVER['FACULTY_DATABASE_URL'] ?? ''));
        if ('' === $databaseUrl) {
            return new InMemoryEventStore($this->topologyProvider);
        }

        return new PostgresEventStore(
            DriverManager::getConnection([
                'url' => $databaseUrl,
            ]),
            $this->topologyProvider,
            self::TABLE_PREFIX,
        );
    }
}
