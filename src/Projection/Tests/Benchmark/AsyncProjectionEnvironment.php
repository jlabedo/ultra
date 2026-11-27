<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Benchmark;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\EventStore\EventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionActivityEventStore;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\ProjectingEventStore;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionStateRepository;
use Ultra\Projection\ProjectionTransactionManagerInterface;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Testing\SqlTrace;
use Ultra\Testing\SqlTraceMiddleware;

final class AsyncProjectionEnvironment
{
    public readonly Connection $connection;
    public readonly UltraNormalizer $normalizer;
    public readonly EventStore $eventStore;
    public readonly ProjectionRunner $runner;
    public readonly ProjectionActivity $activity;
    public readonly InMemoryProjectionActivator $activator;
    public readonly string $schema;
    public readonly string $eventTablePrefix;
    public readonly string $projectionTablePrefix;

    private readonly ProjectionTransactionManagerInterface $transactions;
    private readonly CoordinatorLease $leases;
    private readonly ?ProjectionStateRepository $states;
    private readonly string $normalizerCache;
    private bool $schemaCreated = false;
    private bool $closed = false;

    public function __construct(string $dsn, string $implementation, ?SqlTrace $trace = null)
    {
        $suffix = bin2hex(random_bytes(8));
        $this->schema = 'async_projection_bench_' . $suffix;
        $this->eventTablePrefix = 'ap_' . $suffix . '_event_';
        $this->projectionTablePrefix = 'ap_' . $suffix . '_projection_';
        $this->normalizerCache = sys_get_temp_dir() . '/' . $this->schema;

        $config = new Configuration();
        if (null !== $trace) {
            $config->setMiddlewares([new SqlTraceMiddleware($trace)]);
        }
        $this->connection = PostgresConnectionSupport::connection($dsn, $config);
        try {
            $this->createSchema($implementation);
            $projection = new PostgresProjectionImplementation($this->connection, $this->projectionTablePrefix);
            $this->eventStore = PostgresTestSupport::storeForImplementation(
                $this->connection,
                $this->eventTablePrefix,
                $implementation,
            );
            $this->activity = new ProjectionActivity($projection->createDirtyKeyTracker());
            $this->activator = new InMemoryProjectionActivator();
            $this->leases = $projection->createCoordinatorLease();
            $this->transactions = $projection->createTransactionManager();
            $this->states = method_exists($projection, 'createStateRepository')
                ? $projection->createStateRepository()
                : null;
            $this->runner = new ProjectionRunner(
                $this->eventStore,
                $projection->createSegmentRepository(),
                $this->transactions,
                $this->activity,
                ...$this->states === null ? [] : [$this->states],
            );
            $this->normalizer = new UltraNormalizer(
                cacheDir: $this->normalizerCache,
                generationNamespace: __NAMESPACE__ . '\\Generated',
            );
        } catch (\Throwable $failure) {
            try {
                $this->close();
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    'Benchmark setup and cleanup failed: ' . $cleanupFailure->getMessage(),
                    previous: $failure,
                );
            }

            throw $failure;
        }
    }

    public function appendStore(ProjectionCatalog $catalog): EventStore
    {
        $recorder = new ProjectionActivityRecorder($catalog, $this->activity, $this->leases, $this->activator);

        // The pre-synchronous revision decorated the append only to record projection activity.
        return $this->states === null
            ? new ProjectionActivityEventStore($this->eventStore, $recorder)
            : new ProjectingEventStore($this->eventStore, $catalog, $recorder, $this->transactions, $this->states);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        try {
            if ($this->schemaCreated) {
                PostgresConnectionSupport::resetSharedConnection($this->connection);
                $this->connection->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            }
        } finally {
            $this->connection->close();
            if (is_dir($this->normalizerCache)) {
                foreach (glob($this->normalizerCache . '/*.php') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($this->normalizerCache);
            }
        }
    }

    private function createSchema(string $implementation): void
    {
        $eventStatements = PostgresTestSupport::schemaForImplementation(
            $this->eventTablePrefix,
            $implementation,
        )->createStatements();
        // HStore extensions belong to the database's usual search path and can be reused by other runs.
        foreach ($eventStatements as $index => $statement) {
            if (str_starts_with($statement, 'CREATE EXTENSION ')) {
                $this->connection->executeStatement($statement);
                unset($eventStatements[$index]);
            }
        }

        $searchPath = (string) $this->connection->fetchOne('SHOW search_path');
        $this->connection->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->schemaCreated = true;
        $this->connection->executeStatement("SELECT set_config('search_path', ?, false)", [
            $searchPath === '' ? $this->schema : $this->schema . ', ' . $searchPath,
        ]);
        foreach ([
            ...$eventStatements,
            ...new PostgresProjectionSegmentSchema($this->projectionTablePrefix)->createStatements(),
            ...new PostgresDirtyKeyTrackerSchema($this->projectionTablePrefix . 'dirty_keys')->createStatements(),
            ...new PostgresCoordinatorLeaseSchema($this->projectionTablePrefix
            . 'coordinator_lease')->createStatements(),
        ] as $statement) {
            $this->connection->executeStatement($statement);
        }
    }
}
