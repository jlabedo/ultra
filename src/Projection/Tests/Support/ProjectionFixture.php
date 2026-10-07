<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\Coordination\InMemory\InMemoryCoordinatorLease;
use Ultra\Coordination\InMemory\InMemoryDirtyKeyTracker;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Projection\Coordination\DefaultProjectionScalingPolicy;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\NoOpProjectionTransactionManager;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Projection\ProjectionTransactionManagerInterface;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Time\ClockInterface;
use Ultra\Time\SystemClock;

final class ProjectionFixture
{
    public readonly EventStore $store;
    public readonly ProjectionSegmentRepositoryInterface $segments;
    public readonly ProjectionActivity $activity;
    public readonly CoordinatorLease $leases;
    public readonly InMemoryProjectionActivator $activator;
    public readonly ProjectionTransactionManagerInterface $transactions;
    public readonly ProjectionRunner $runner;
    public readonly ?Connection $connection;
    public readonly string $prefix;
    /** @var list<object{createStatements(): list<string>, dropStatements(): list<string>}> */
    private array $schemas = [];
    /** @var list<string> */
    private array $readModels = [];

    public function __construct(string $backend, ?ClockInterface $clock = null)
    {
        $clock ??= new SystemClock();
        $this->activator = new InMemoryProjectionActivator();
        $this->prefix = 'projection_guarantees_' . bin2hex(random_bytes(4)) . '_';

        if ('memory' === $backend) {
            $this->connection = null;
            $this->store = new InMemoryEventStore();
            $this->segments = new InMemoryProjectionSegmentRepository($clock);
            $this->activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
            $this->leases = new InMemoryCoordinatorLease($clock);
            $this->transactions = new NoOpProjectionTransactionManager();
        } else {
            $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
            if (false === $dsn || '' === $dsn) {
                TestCase::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN for Postgres projection guarantees.');
            }
            $this->connection = PostgresConnectionSupport::sharedConnection($dsn);
            $eventPrefix = $this->prefix . 'event_';
            $this->schemas = [
                PostgresTestSupport::schemaForImplementation($eventPrefix, 'query-streams'),
                new PostgresProjectionSegmentSchema($this->prefix),
                new PostgresDirtyKeyTrackerSchema($this->prefix . 'dirty_keys'),
                new PostgresCoordinatorLeaseSchema($this->prefix . 'coordinator_lease'),
            ];
            $statements = [];
            foreach ($this->schemas as $schema) {
                $statements = [...$statements, ...$schema->createStatements()];
            }
            $this->executeSchemaStatements($statements);
            $implementation = new PostgresProjectionImplementation($this->connection, $this->prefix, $clock);
            $this->store = PostgresTestSupport::storeForImplementation(
                $this->connection,
                $eventPrefix,
                'query-streams',
            );
            $this->segments = $implementation->createSegmentRepository();
            $this->activity = new ProjectionActivity($implementation->createDirtyKeyTracker());
            $this->leases = $implementation->createCoordinatorLease();
            $this->transactions = $implementation->createTransactionManager();
        }
        $this->runner = new ProjectionRunner($this->store, $this->segments, $this->transactions, $this->activity);
    }

    public function coordinator(
        ProjectionRuntime $runtime,
        ?ProjectionScalingPolicyInterface $scaling = null,
    ): ProjectionCoordinator {
        return new ProjectionCoordinator(
            new ProjectionCatalog($runtime),
            $this->runner,
            $this->activity,
            $this->leases,
            $this->activator,
            $scaling ?? new DefaultProjectionScalingPolicy(),
        );
    }

    public function durableProjection(): DurableProjection
    {
        $table = $this->prefix . 'read_model';
        $this->registerReadModel($table);

        return new DurableProjection(
            $this->connection ?? throw new \LogicException('A durable projection needs Postgres.'),
            $table,
        );
    }

    public function registerReadModel(string $table): void
    {
        $this->readModels[] = $table;
    }

    public function close(): void
    {
        if (null === $this->connection) {
            return;
        }
        PostgresConnectionSupport::resetSharedConnection($this->connection);
        $statements = [];
        foreach ($this->readModels as $table) {
            $statements[] = 'DROP TABLE IF EXISTS ' . $table;
        }
        foreach (array_reverse($this->schemas) as $schema) {
            $statements = [...$statements, ...$schema->dropStatements()];
        }
        $this->executeSchemaStatements($statements);
    }

    /** @param list<string> $statements */
    private function executeSchemaStatements(array $statements): void
    {
        // The native pgsql driver reads one result: a DO block reports every DDL error through it.
        $this->connection->executeStatement(
            "DO \$projection_fixture_ddl\$\nBEGIN\n"
            . implode(";\n", $statements)
            . ";\nEND;\n\$projection_fixture_ddl\$",
        );
    }
}
