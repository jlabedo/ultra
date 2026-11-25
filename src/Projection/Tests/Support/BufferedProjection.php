<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;
use Ultra\UnitOfWork\UnitOfWork;

#[Projection(name: 'buffered_projection', partitionBy: 'courseId')]
final class BufferedProjection implements UnitOfWork
{
    private readonly MappedRowSet $courses;
    private readonly MappedRowSet $index;
    private readonly MappedTableUnitOfWork $persistence;

    public ?string $failAt = null;

    public function __construct(
        private readonly Connection $connection,
        public readonly string $coursesTable,
        public readonly string $indexTable,
    ) {
        $adapter = new PostgresMappedTableAdapter($connection);
        $columns = [
            new ColumnDefinition('id', type: ColumnType::Text),
            new ColumnDefinition('name', type: ColumnType::Text),
        ];
        $schema = new SchemaDefinition([
            new TableDefinition($coursesTable, columns: $columns, primaryKey: ['id']),
            new TableDefinition($indexTable, columns: $columns, primaryKey: ['id']),
        ]);
        $this->courses = $adapter->compileRowSet($schema, $coursesTable)();
        $this->index = $adapter->compileRowSet($schema, $indexTable)();
        $this->persistence = new MappedTableUnitOfWork([
            $coursesTable => $this->courses,
            $indexTable => $this->index,
        ], $adapter);
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
    }

    #[ProjectionHandler]
    public function apply(RunnerCourseCreated $event): void
    {
        $this->courses->withKey($event->courseId)->insert()->setColumn('name', $event->name);
        $this->index
            ->withKey($event->courseId)
            ->insert()
            ->setColumn('name', 'buffer' === $this->failAt ? 'rejected' : $event->name);
        $this->failIf('apply');
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
        return $this->persistence->hasPendingChanges();
    }

    public function flush(): void
    {
        $this->persistence->flush();
        $this->failIf('reset flush');
    }

    public function clear(): void
    {
        $this->persistence->clear();
    }

    private function failIf(string $stage): void
    {
        if ($stage === $this->failAt) {
            throw new \RuntimeException('Failed ' . $stage . '.');
        }
    }
}
