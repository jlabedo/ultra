<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\InMemory\InMemoryProjectionStateRepository;
use Ultra\Projection\ProjectingEventStore;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionMode;
use Ultra\Projection\ProjectionRebuilder;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionStateRepository;
use Ultra\Projection_Postgres\PostgresProjectionStateRepository;
use Ultra\UnitOfWork\UnitOfWork;

abstract class ModeProjection
{
    public ?string $failAt = null;
    public int $initialized = 0;
    /** @var list<array{courseId: string, name: string}> */
    public array $stored = [];

    public function __construct(
        public ?Connection $connection = null,
        public string $table = '',
    ) {}

    #[ProjectionInitializer]
    public function initialize(): void
    {
        ++$this->initialized;
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->stored = [];
        $this->connection?->executeStatement('DELETE FROM ' . $this->table);
    }

    #[ProjectionHandler]
    public function apply(RunnerCourseCreated $event): void
    {
        $this->write(['courseId' => $event->courseId, 'name' => $event->name]);
        if ('apply' === $this->failAt) {
            throw new \RuntimeException('Failed apply.');
        }
    }

    #[ProjectionFlush]
    public function finish(): void
    {
        if ('finish' === $this->failAt) {
            throw new \RuntimeException('Failed finish.');
        }
    }

    /** @param array{courseId: string, name: string} $row */
    protected function write(array $row): void
    {
        $this->persist($row);
    }

    /** @param array{courseId: string, name: string} $row */
    protected function persist(array $row): void
    {
        if (null === $this->connection) {
            $this->stored[] = $row;
        } else {
            $this->connection->executeStatement(
                'INSERT INTO ' . $this->table . ' (course_id, name) VALUES (?, ?)',
                array_values($row),
            );
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return null === $this->connection
            ? array_column($this->stored, 'name')
            : $this->connection->fetchFirstColumn('SELECT name FROM ' . $this->table . ' ORDER BY position');
    }
}

abstract class BufferedModeProjection extends ModeProjection implements UnitOfWork
{
    /** @var list<array{courseId: string, name: string}> */
    public array $pending = [];
    public int $clears = 0;

    protected function write(array $row): void
    {
        $this->pending[] = $row;
    }

    public function hasPendingChanges(): bool
    {
        return [] !== $this->pending;
    }

    public function flush(): void
    {
        foreach ($this->pending as $row) {
            $this->persist($row);
            if ('persistence' === $this->failAt) {
                throw new \RuntimeException('Failed persistence.');
            }
        }
    }

    public function clear(): void
    {
        ++$this->clears;
        $this->pending = [];
    }
}

#[Projection(name: 'mode_projection', mode: ProjectionMode::SynchronousFromNow)]
final class ImmediateModeProjection extends ModeProjection {}

#[Projection(name: 'mode_projection', mode: ProjectionMode::SynchronousFromNow)]
final class ImmediateBufferedModeProjection extends BufferedModeProjection {}

#[Projection(name: 'mode_projection', partitionBy: 'courseId', mode: ProjectionMode::Synchronous)]
final class ManagedModeProjection extends ModeProjection {}

#[Projection(name: 'mode_projection', partitionBy: 'courseId', mode: ProjectionMode::Synchronous)]
final class ManagedBufferedModeProjection extends BufferedModeProjection {}

#[Projection(name: 'mode_projection', partitionBy: 'courseId')]
final class AsyncModeProjection extends ModeProjection {}

#[Projection(name: 'mode_projection', partitionBy: 'courseId')]
final class AsyncBufferedModeProjection extends BufferedModeProjection {}

/** One composition for every mode, with and without private buffered persistence. */
final readonly class ProjectionModeFixture
{
    public ModeProjection $projection;
    public ProjectionRuntime $runtime;
    public ProjectionCatalog $catalog;
    public ProjectionStateRepository $states;
    public ProjectingEventStore $store;
    public ProjectionRunner $runner;
    public ProjectionRebuilder $rebuilder;

    public function __construct(
        public ProjectionFixture $fixture,
        ProjectionMode $mode,
        bool $buffered = false,
    ) {
        $class = match ($mode) {
            ProjectionMode::Asynchronous => $buffered ? AsyncBufferedModeProjection::class : AsyncModeProjection::class,
            ProjectionMode::Synchronous => $buffered
                ? ManagedBufferedModeProjection::class
                : ManagedModeProjection::class,
            ProjectionMode::SynchronousFromNow => $buffered
                ? ImmediateBufferedModeProjection::class
                : ImmediateModeProjection::class,
        };
        $table = $fixture->prefix . 'mode_model';
        if (null !== $fixture->connection) {
            $fixture->registerReadModel($table);
            $fixture->connection->executeStatement(
                'CREATE TABLE ' . $table . ' (position BIGSERIAL PRIMARY KEY, course_id TEXT, name TEXT)',
            );
        }
        $this->projection = new $class($fixture->connection, $table);
        $this->runtime = new ProjectionRuntime($this->projection, new UltraNormalizer());
        $this->catalog = new ProjectionCatalog($this->runtime);
        $this->states = null === $fixture->connection
            ? new InMemoryProjectionStateRepository()
            : new PostgresProjectionStateRepository($fixture->connection, $fixture->prefix);
        $recorder = new ProjectionActivityRecorder(
            $this->catalog,
            $fixture->activity,
            $fixture->leases,
            $fixture->activator,
        );
        $this->store = new ProjectingEventStore(
            $fixture->store,
            $this->catalog,
            $recorder,
            $fixture->transactions,
            $this->states,
        );
        $this->runner = new ProjectionRunner(
            $fixture->store,
            $fixture->segments,
            $fixture->transactions,
            $fixture->activity,
            $this->states,
        );
        $this->rebuilder = new ProjectionRebuilder(
            $fixture->store,
            $fixture->segments,
            $this->states,
            $fixture->transactions,
            $this->runner,
        );
    }
}

final class OwnedProjectionRows implements UnitOfWork
{
    public array $pending = [];
    public array $stored = [];
    public int $flushes = 0;

    public function hasPendingChanges(): bool
    {
        return [] !== $this->pending;
    }

    public function flush(): void
    {
        ++$this->flushes;
        array_push($this->stored, ...$this->pending);
    }

    public function clear(): void
    {
        $this->pending = [];
    }
}

#[Projection(
    name: 'owned_projection',
    mode: ProjectionMode::SynchronousFromNow,
    unitOfWork: [OwnedProjectionRows::class],
)]
final readonly class OwnedPersistenceProjection
{
    public function __construct(
        public OwnedProjectionRows $rows,
    ) {}

    #[ProjectionHandler]
    public function apply(RunnerCourseCreated $event): void
    {
        $this->rows->pending[] = $event->name;
    }
}

#[Projection(
    name: 'second_owned_projection',
    mode: ProjectionMode::SynchronousFromNow,
    unitOfWork: [OwnedProjectionRows::class],
)]
final readonly class ConflictingPersistenceProjection
{
    #[ProjectionHandler]
    public function apply(RunnerCourseCreated $event): void {}
}

#[Projection(name: 'missing_partition')]
final class MissingAsyncPartitionProjection {}

#[Projection(name: 'missing_partition', mode: ProjectionMode::Synchronous)]
final class MissingManagedPartitionProjection {}

#[Projection(name: 'mode_projection', partitionBy: 'courseId', mode: ProjectionMode::Synchronous)]
final class BufferedLifecycleProjection extends BufferedModeProjection
{
    #[ProjectionInitializer]
    public function initialize(): void
    {
        $this->write(['courseId' => 'lifecycle', 'name' => 'initialize']);
        if ('initialize' === $this->failAt) {
            throw new \RuntimeException('Failed initialize.');
        }
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->write(['courseId' => 'lifecycle', 'name' => 'reset']);
    }
}

#[Projection(name: 'second_immediate_projection', mode: ProjectionMode::SynchronousFromNow)]
final class SecondImmediateModeProjection extends BufferedModeProjection {}

#[Projection(name: 'async_projection', partitionBy: 'courseId')]
final class AdditionalAsyncModeProjection extends BufferedModeProjection {}
