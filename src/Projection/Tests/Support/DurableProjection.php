<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Attribute\ProjectionResetter;

#[Projection(name: 'durable_projection', partitionBy: 'courseId')]
final class DurableProjection
{
    public ?string $failAt = null;

    public function __construct(public readonly Connection $connection, public readonly string $table) {}

    #[ProjectionInitializer]
    public function initialize(): void
    {
        $this->connection->executeStatement('CREATE TABLE '.$this->table.' (position SERIAL PRIMARY KEY, action TEXT NOT NULL, course_id TEXT, name TEXT)');
        $this->record('initialize');
    }

    #[ProjectionHandler]
    public function apply(RunnerCourseCreated $event): void
    {
        $this->record('apply', $event->courseId, $event->name);
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        $this->record('flush');
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->table);
        if ('reset' === $this->failAt) {
            throw new \RuntimeException('Failed reset.');
        }
    }

    /** @return list<array{action: string, course_id: ?string, name: ?string}> */
    public function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT action, course_id, name FROM '.$this->table.' ORDER BY position');
    }

    private function record(string $action, ?string $id = null, ?string $name = null): void
    {
        $this->connection->executeStatement('INSERT INTO '.$this->table.' (action, course_id, name) VALUES (?, ?, ?)', [$action, $id, $name]);
        if ($action === $this->failAt) {
            throw new \RuntimeException('Failed '.$action.'.');
        }
    }
}
