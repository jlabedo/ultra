<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests\Harness;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreamsSchema;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Projection_Postgres\PostgresProjectionTransactionManager;
use Ultra\Testing\PostgresConnectionSupport;

#[EventAttribute('harness.course.created')]
final readonly class HarnessCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: ProjectionCoordinationHarness::PROJECTION_ID, partitionBy: 'courseId')]
final readonly class HarnessCourseNamesProjection
{
    private MappedRowSet $table;

    public function __construct(
        private Connection $connection,
        private string $tableName,
    ) {
        $this->table = new MappedRowSet(
            new PostgresMappedRowSetAdapter($this->connection, $this->tableName, ['course_id']),
        );
    }

    #[ProjectionInitializer]
    public function initialize(): void
    {
        $this->connection->executeStatement(sprintf('CREATE TABLE IF NOT EXISTS %s (
                course_id TEXT PRIMARY KEY,
                name      TEXT NOT NULL
            )', $this->tableName));
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $this->tableName));
    }

    #[ProjectionHandler]
    public function onCourseCreated(HarnessCourseCreated $event): void
    {
        $this->table->withKey($event->courseId)->upsert()->setColumn('name', $event->name);
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        $this->table->flush();
        $this->table->clear();
    }
}

final class ProjectionCoordinationHarness
{
    public const string PROJECTION_ID = 'harness_course_names';

    public static function createApplication(
        string $dsn,
        string $eventStorePrefix,
        string $projectionPrefix,
        ?string $consolePath = null,
    ): Application {
        $connection = self::connection($dsn);
        $eventStore = new PostgresEventStoreQueryStreams($connection, $eventStorePrefix);
        $segments = new PostgresProjectionSegmentRepository($connection, $projectionPrefix);
        $activity = new ProjectionActivity(new PostgresDirtyKeyTracker($connection, $projectionPrefix . 'dirty_keys'));
        $leases = new PostgresCoordinatorLease($connection, $projectionPrefix . 'coordinator_lease');
        $runtime = self::runtime($connection, $projectionPrefix);
        $catalog = new ProjectionCatalog($runtime);
        $runner = new ProjectionRunner(
            $eventStore,
            $segments,
            new PostgresProjectionTransactionManager($connection),
            $activity,
        );
        $consolePath ??= __DIR__ . '/projection-coordination-harness';

        $application = new Application('Projection Coordination Harness');
        $application->setAutoExit(false);
        $processActivator = new ProcessProjectionActivator($consolePath, $dsn, $eventStorePrefix, $projectionPrefix);
        $scalingPolicy = new ConfigurableProjectionScalingPolicy();
        $coordinator = new ProjectionCoordinator(
            $catalog,
            $runner,
            $activity,
            $leases,
            $processActivator,
            $scalingPolicy,
        );

        $application->addCommands([
            new CreateProjectionHarnessSchemaCommand($connection, $eventStorePrefix, $projectionPrefix),
            new SeedProjectionEventsCommand($eventStore, $activity),
            new BackfillProjectionCommand($catalog, $runner, $processActivator),
            new ResetProjectionCommand($catalog, $runner),
            new RunProjectionHarnessCommand($connection, $eventStorePrefix, $projectionPrefix),
            new ProjectionWorkerCommand($catalog, $runner),
        ]);

        return $application;
    }

    public static function connection(string $dsn): Connection
    {
        return PostgresConnectionSupport::sharedConnection($dsn);
    }

    public static function readModelTable(string $projectionPrefix): string
    {
        return $projectionPrefix . 'harness_course_names_view';
    }

    /** @return list<object{createStatements(): list<string>, dropStatements(): list<string>}> */
    public static function schemas(string $eventStorePrefix, string $projectionPrefix): array
    {
        return [
            new PostgresEventStoreQueryStreamsSchema($eventStorePrefix),
            new PostgresProjectionSegmentSchema($projectionPrefix),
            new PostgresCoordinatorLeaseSchema($projectionPrefix . 'coordinator_lease'),
            new PostgresDirtyKeyTrackerSchema($projectionPrefix . 'dirty_keys'),
        ];
    }

    private static function runtime(Connection $connection, string $projectionPrefix): ProjectionRuntime
    {
        return new ProjectionRuntime(
            new HarnessCourseNamesProjection($connection, self::readModelTable($projectionPrefix)),
            new UltraNormalizer(),
        );
    }
}

final class CreateProjectionHarnessSchemaCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $eventStorePrefix,
        private readonly string $projectionPrefix,
    ) {
        parent::__construct('harness:projection-schema:create');
    }

    public function createSchema(bool $reset): void
    {
        $schemas = ProjectionCoordinationHarness::schemas($this->eventStorePrefix, $this->projectionPrefix);

        if ($reset) {
            foreach (array_reverse($schemas) as $schema) {
                foreach ($schema->dropStatements() as $statement) {
                    $this->connection->executeStatement($statement);
                }
            }

            $this->connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s',
                ProjectionCoordinationHarness::readModelTable($this->projectionPrefix),
            ));
        }

        foreach ($schemas as $schema) {
            foreach ($schema->createStatements() as $statement) {
                $this->connection->executeStatement($statement);
            }
        }
    }

    protected function configure(): void
    {
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Drop harness tables before creating them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->createSchema((bool) $input->getOption('reset'));
        $output->writeln(sprintf(
            'Projection harness schema is ready. event_prefix=%s projection_prefix=%s',
            $this->eventStorePrefix,
            $this->projectionPrefix,
        ));

        return Command::SUCCESS;
    }
}

final class SeedProjectionEventsCommand extends Command
{
    private const int BATCH_SIZE = 1000;

    public function __construct(
        private readonly PostgresEventStoreQueryStreams $eventStore,
        private readonly ProjectionActivity $activity,
    ) {
        parent::__construct('harness:projection-seed');
    }

    protected function configure(): void
    {
        $this->addArgument('event-count', InputArgument::OPTIONAL, 'Number of course-created events to seed.', 32);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $eventCount = (int) $input->getArgument('event-count');
        if ($eventCount < 1) {
            throw new \InvalidArgumentException('event-count must be greater than zero.');
        }

        $seeded = 0;
        $events = [];
        $buckets = [];
        for ($i = 1; $i <= $eventCount; ++$i) {
            $courseId = sprintf('course-%03d', $i);
            $events[] = new Event(
                eventType: 'harness.course.created',
                payload: ['courseId' => $courseId, 'name' => sprintf('Course %03d', $i)],
                domainIds: ['courseId' => $courseId],
            );
            $buckets[BucketHasher::bucketFor($courseId)] = true;

            if (self::BATCH_SIZE === count($events)) {
                $this->eventStore->append($events);
                foreach ($buckets as $bucket => $true) {
                    $this->activity->bump(ProjectionCoordinationHarness::PROJECTION_ID, $bucket);
                }
                $seeded += count($events);
                $events = [];
                $buckets = [];
            }
        }

        if ([] !== $events) {
            $this->eventStore->append($events);
            foreach ($buckets as $bucket => $true) {
                $this->activity->bump(ProjectionCoordinationHarness::PROJECTION_ID, $bucket);
            }
            $seeded += count($events);
        }

        $output->writeln(sprintf('Seeded %d projection events in batches of %d.', $seeded, self::BATCH_SIZE));

        return Command::SUCCESS;
    }
}

final class BackfillProjectionCommand extends Command
{
    public function __construct(
        private readonly ProjectionCatalog $catalog,
        private readonly ProjectionRunner $runner,
        private readonly ProcessProjectionActivator $activator,
    ) {
        parent::__construct('harness:projection-backfill');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workers', InputArgument::REQUIRED, 'Number of worker processes to start.')
            ->addOption(
                'projection-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Projection identifier.',
                ProjectionCoordinationHarness::PROJECTION_ID,
            )
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events processed per claimed segment.', 5)
            ->addOption('max-cycles', null, InputOption::VALUE_REQUIRED, 'Maximum coordinator cycles per worker.', 64);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workers = (int) $input->getArgument('workers');
        if ($workers < 1) {
            throw new \InvalidArgumentException('workers must be greater than zero.');
        }

        $batchSize = (int) $input->getOption('batch-size');
        $maxCycles = (int) $input->getOption('max-cycles');
        if ($batchSize < 1 || $maxCycles < 1) {
            throw new \InvalidArgumentException('batch-size and max-cycles must be greater than zero.');
        }

        $projectionId = (string) $input->getOption('projection-id');
        $runtime = $this->catalog->runtime($projectionId);

        $this->runner->ensureInitialized($runtime);
        $this->runner->recoverExpiredClaims($projectionId);
        $this->splitUntilSegmentCount($projectionId, $workers);

        $this->activator->configureRun($batchSize, $maxCycles, backfill: true);
        foreach (array_slice($this->runner->availableSegments($projectionId), 0, $workers) as $segment) {
            $this->activator->activateWorker(
                $projectionId,
                $segment->bucketFrom,
                sprintf('process-backfill-worker-%d', $segment->bucketFrom),
            );
        }

        return $this->activator->wait($output) ? Command::SUCCESS : Command::FAILURE;
    }

    private function splitUntilSegmentCount(string $projectionId, int $targetSegmentCount): void
    {
        while (count($this->runner->listSegments($projectionId)) < $targetSegmentCount) {
            $candidate = null;
            foreach ($this->runner->listSegments($projectionId) as $segment) {
                if (null !== $segment->workerId || $segment->bucketTo <= $segment->bucketFrom) {
                    continue;
                }

                if (
                    null === $candidate
                    || ($segment->bucketTo - $segment->bucketFrom) > ($candidate->bucketTo - $candidate->bucketFrom)
                ) {
                    $candidate = $segment;
                }
            }

            if (null === $candidate) {
                return;
            }

            $this->runner->splitSegment($projectionId, $candidate->bucketFrom);
        }
    }
}

final class ResetProjectionCommand extends Command
{
    public function __construct(
        private readonly ProjectionCatalog $catalog,
        private readonly ProjectionRunner $runner,
    ) {
        parent::__construct('harness:projection-reset');
    }

    protected function configure(): void
    {
        $this->addOption(
            'projection-id',
            null,
            InputOption::VALUE_REQUIRED,
            'Projection identifier.',
            ProjectionCoordinationHarness::PROJECTION_ID,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectionId = (string) $input->getOption('projection-id');

        $this->runner->deleteProjection($this->catalog->runtime($projectionId));

        $output->writeln(sprintf('Projection "%s" was reset.', $projectionId));

        return Command::SUCCESS;
    }
}

final class ConfigurableProjectionScalingPolicy implements ProjectionScalingPolicyInterface
{
    public int $additionalWorkers = 0;

    public function additionalWorkersRequested(array $affectedBuckets): int
    {
        return $this->additionalWorkers;
    }
}

final class RunProjectionHarnessCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $eventStorePrefix,
        private readonly string $projectionPrefix,
    ) {
        parent::__construct('harness:projection-test');
    }

    protected function configure(): void
    {
        $this
            ->addOption('events', null, InputOption::VALUE_REQUIRED, 'Number of events to seed.', 32)
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'Number of worker processes to start.', 4)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events processed per claimed segment.', 3)
            ->addOption('max-cycles', null, InputOption::VALUE_REQUIRED, 'Maximum coordinator cycles per worker.', 128)
            ->addOption('reset-schema', null, InputOption::VALUE_NONE, 'Drop harness tables before running.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $events = (int) $input->getOption('events');
        $workers = (int) $input->getOption('workers');
        $batchSize = (int) $input->getOption('batch-size');
        $maxCycles = (int) $input->getOption('max-cycles');

        if ($events < 1 || $workers < 1 || $batchSize < 1 || $maxCycles < 1) {
            throw new \InvalidArgumentException(
                'events, workers, batch-size, and max-cycles must be greater than zero.',
            );
        }

        $application = $this->getApplication();
        if (!$application instanceof Application) {
            throw new \LogicException('The harness test command must be registered in an application.');
        }

        $schema = $application->find('harness:projection-schema:create');
        if (!$schema instanceof CreateProjectionHarnessSchemaCommand) {
            throw new \LogicException('Schema command is not registered.');
        }

        $schema->createSchema((bool) $input->getOption('reset-schema'));
        $output->writeln('Schema ready.');

        $seedCode = $application->find('harness:projection-seed')->run(new ArrayInput([
            'event-count' => (string) $events,
        ]), $output);
        if (Command::SUCCESS !== $seedCode) {
            return $seedCode;
        }

        $backfillCode = $application->find('harness:projection-backfill')->run(new ArrayInput([
            'workers' => (string) $workers,
            '--batch-size' => (string) $batchSize,
            '--max-cycles' => (string) $maxCycles,
        ]), $output);
        if (Command::SUCCESS !== $backfillCode) {
            return $backfillCode;
        }

        $projectedRows = (int) $this->connection->fetchOne(sprintf(
            'SELECT count(*) FROM %s',
            ProjectionCoordinationHarness::readModelTable($this->projectionPrefix),
        ));
        $pendingBuckets = (int) $this->connection->fetchOne(sprintf(
            'SELECT count(*) FROM %s WHERE counter > 0',
            $this->projectionPrefix . 'dirty_keys',
        ));

        $output->writeln(sprintf('Projected rows: %d', $projectedRows));
        $output->writeln(sprintf('Pending buckets: %d', $pendingBuckets));
        $output->writeln(sprintf(
            'Tables were left in Postgres. event_prefix=%s projection_prefix=%s',
            $this->eventStorePrefix,
            $this->projectionPrefix,
        ));

        return $projectedRows === $events ? Command::SUCCESS : Command::FAILURE;
    }
}

final class ProjectionWorkerCommand extends Command
{
    public function __construct(
        private readonly ProjectionCatalog $catalog,
        private readonly ProjectionRunner $runner,
    ) {
        parent::__construct('harness:projection-worker');
    }

    protected function configure(): void
    {
        $this
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED, 'Worker identifier.', gethostname() ?: 'worker')
            ->addOption('projection-id', null, InputOption::VALUE_REQUIRED, 'Projection identifier.')
            ->addOption('bucket-from', null, InputOption::VALUE_REQUIRED, 'Claimed segment start bucket.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events processed per claimed segment.', 5)
            ->addOption('max-cycles', null, InputOption::VALUE_REQUIRED, 'Maximum worker cycles.', 64)
            ->addOption(
                'backfill',
                null,
                InputOption::VALUE_NONE,
                'Run claimed work without consulting bucket activity.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = (string) $input->getOption('worker-id');
        $projectionId = (string) $input->getOption('projection-id');
        $bucketFrom = (int) $input->getOption('bucket-from');
        $runtime = $this->catalog->runtime($projectionId);
        $batchSize = (int) $input->getOption('batch-size');
        $maxCycles = (int) $input->getOption('max-cycles');
        $backfill = (bool) $input->getOption('backfill');

        $processedEvents = 0;
        $acknowledgedBuckets = 0;
        $caughtUp = false;
        for ($cycle = 0; $cycle < $maxCycles; ++$cycle) {
            $result = $backfill
                ? $this->runner->backfillClaimedOnce($runtime, $projectionId, $bucketFrom, $workerId, $batchSize)
                : $this->runner->runClaimedOnce($runtime, $projectionId, $bucketFrom, $workerId, $batchSize);
            if (!$result->claimedSegment) {
                break;
            }

            $processedEvents += $result->processedEvents;
            $acknowledgedBuckets += $result->acknowledgedBuckets;
            $caughtUp = $result->caughtUp;
            if ($caughtUp) {
                break;
            }
        }

        $output->writeln(sprintf(
            'worker=%s projection=%s bucket_from=%d caught_up=%s processed=%d acknowledged=%d',
            $workerId,
            $projectionId,
            $bucketFrom,
            $caughtUp ? 'yes' : 'no',
            $processedEvents,
            $acknowledgedBuckets,
        ));

        return $backfill && !$caughtUp ? Command::FAILURE : Command::SUCCESS;
    }
}

final class ProcessProjectionActivator implements ProjectionActivatorInterface
{
    /** @var list<Process> */
    private array $processes = [];

    private int $sequence = 0;
    private int $batchSize = 5;
    private int $maxCycles = 64;
    private bool $backfill = false;

    public function __construct(
        private readonly string $consolePath,
        private readonly string $dsn,
        private readonly string $eventStorePrefix,
        private readonly string $projectionPrefix,
    ) {}

    public function configureRun(int $batchSize, int $maxCycles, bool $backfill = false): void
    {
        $this->batchSize = $batchSize;
        $this->maxCycles = $maxCycles;
        $this->backfill = $backfill;
    }

    public function signalWorkAvailable(): void
    {
        // The harness uses the backfill command as the scheduler entrypoint.
    }

    public function activateWorker(string $projectionId, int $bucketFrom, string $workerId): void
    {
        ++$this->sequence;
        $process = new Process(
            [
                PHP_BINARY,
                $this->consolePath,
                'harness:projection-worker',
                '--worker-id=' . $workerId,
                '--projection-id=' . $projectionId,
                '--bucket-from=' . (string) $bucketFrom,
                '--batch-size=' . $this->batchSize,
                '--max-cycles=' . $this->maxCycles,
                ...($this->backfill ? ['--backfill'] : []),
            ],
            null,
            [
                'ULTRA_PROJECTION_HARNESS_DSN' => $this->dsn,
                'ULTRA_PROJECTION_HARNESS_EVENT_PREFIX' => $this->eventStorePrefix,
                'ULTRA_PROJECTION_HARNESS_PROJECTION_PREFIX' => $this->projectionPrefix,
            ],
        );
        $process->setTimeout(60);
        $process->start();
        $this->processes[] = $process;
    }

    public function wait(OutputInterface $output): bool
    {
        $successful = true;
        foreach ($this->processes as $process) {
            $exitCode = $process->wait();
            $output->write($process->getOutput());

            if (0 !== $exitCode) {
                $successful = false;
                $output->write($process->getErrorOutput());
            }
        }

        return $successful;
    }
}
