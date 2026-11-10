<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\UnitOfWork\UnitOfWork;

#[Projection(name: 'buffered_projection', partitionBy: 'courseId')]
final class BufferedProjection implements UnitOfWork
{
    private readonly MappedRowSet $courses;
    private readonly MappedRowSet $index;

    public ?string $failAt = null;

    public function __construct(
        private readonly Connection $connection,
        public readonly string $coursesTable,
        public readonly string $indexTable,
    ) {
        $this->courses = new MappedRowSet(new PostgresMappedRowSetAdapter($connection, $coursesTable));
        $this->index = new MappedRowSet(new PostgresMappedRowSetAdapter($connection, $indexTable));
    }

    #[ProjectionInitializer]
    public function initialize(): void
    {
        foreach ([$this->coursesTable, $this->indexTable] as $table) {
            $this->connection->executeStatement(
                'CREATE TABLE ' . $table . " (id TEXT PRIMARY KEY, name TEXT NOT NULL CHECK (name <> 'rejected'))",
            );
        }
        $this->courses->withKey('init')->insert()->setColumn('name', 'initialized');
        $this->index->withKey('init')->insert()->setColumn('name', 'initialized');
        $this->failIf('initialize');
    }

    #[ProjectionHandler]
    public function apply(RunnerCourseCreated $event): void
    {
        $this->courses->withKey($event->courseId)->insert()->setColumn('name', $event->name);
        $this->index->withKey($event->courseId)->insert()->setColumn(
            'name',
            'buffer' === $this->failAt ? 'rejected' : $event->name,
        );
        $this->failIf('apply');
    }

    #[ProjectionFlush]
    public function prepareFlush(): void
    {
        $this->courses->withKey('init')->setColumn('name', 'flushed');
        $this->failIf('hook');
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->courses->reconcileRows([]);
        $this->index->reconcileRows([]);
        $this->failIf('reset');
    }

    public function hasPendingChanges(): bool
    {
        return $this->courses->hasPendingChanges() || $this->index->hasPendingChanges();
    }

    public function flush(): void
    {
        $this->courses->flush();
        $this->index->flush();
    }

    public function clear(): void
    {
        $this->courses->clear();
        $this->index->clear();
    }

    private function failIf(string $stage): void
    {
        if ($stage === $this->failAt) {
            throw new \RuntimeException('Failed ' . $stage . '.');
        }
    }
}
