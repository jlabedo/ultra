<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests\Harness;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreamsSchema;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivityRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivitySchema;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;

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
    public function __construct(
        private Connection $connection,
        private string $tableName,
    ) {}

    #[ProjectionInitializer]
    public function initialize(): void
    {
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                course_id TEXT PRIMARY KEY,
                name      TEXT NOT NULL
            )',
            $this->tableName,
        ));
    }

    #[ProjectionHandler]
    public function onCourseCreated(HarnessCourseCreated $event): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (course_id, name) VALUES (?, ?)
                 ON CONFLICT (course_id) DO UPDATE SET name = EXCLUDED.name',
                $this->tableName,
            ),
            [$event->courseId, $event->name],
            [ParameterType::STRING, ParameterType::STRING],
        );
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
        $activity = new PostgresProjectionBucketActivityRepository($connection, $projectionPrefix);
        $leases = new PostgresProjectionCoordinatorLeaseRepository($connection, $projectionPrefix);
        $runtime = self::runtime($connection, $projectionPrefix);
        $catalog = new ProjectionCatalog($runtime);
        $runner = new ProjectionRunner($eventStore, $segments);
        $consolePath ??= __DIR__.'/projection-coordination-harness';

        $application = new Application('Projection Coordination Harness');
        $application->setAutoExit(false);
        $application->addCommands([
            new CreateProjectionHarnessSchemaCommand($connection, $eventStorePrefix, $projectionPrefix),
            new SeedProjectionEventsCommand($eventStore, $activity),
            new BackfillProjectionCommand(
                $runtime,
                $segments,
                new ProcessProjectionActivator($consolePath, $dsn, $eventStorePrefix, $projectionPrefix),
            ),
            new RunProjectionHarnessCommand($connection, $eventStorePrefix, $projectionPrefix),
            new ProjectionWorkerCommand(new ProjectionCoordinator(
                $catalog,
                $runner,
                $activity,
                $leases,
                new InMemoryProjectionActivator(),
            )),
        ]);

        return $application;
    }

    public static function connection(string $dsn): Connection
    {
        return DriverManager::getConnection(
            new DsnParser([
                'postgres' => 'pgsql',
                'postgresql' => 'pgsql',
            ])->parse($dsn),
        );
    }

    public static function readModelTable(string $projectionPrefix): string
    {
        return $projectionPrefix.'harness_course_names_view';
    }

    /** @return list<object{createStatements(): list<string>, dropStatements(): list<string>}> */
    public static function schemas(string $eventStorePrefix, string $projectionPrefix): array
    {
        return [
            new PostgresEventStoreQueryStreamsSchema($eventStorePrefix),
            new PostgresProjectionSegmentSchema($projectionPrefix),
            new PostgresProjectionCoordinatorSchema($projectionPrefix),
            new PostgresProjectionBucketActivitySchema($projectionPrefix),
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
        private readonly PostgresProjectionBucketActivityRepository $activity,
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

        $output->writeln(sprintf(
            'Seeded %d projection events in batches of %d.',
            $seeded,
            self::BATCH_SIZE,
        ));

        return Command::SUCCESS;
    }
}

final class BackfillProjectionCommand extends Command
{
    public function __construct(
        private readonly ProjectionRuntime $runtime,
        private readonly PostgresProjectionSegmentRepository $segments,
        private readonly ProcessProjectionActivator $activator,
    ) {
        parent::__construct('harness:projection-backfill');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workers', InputArgument::REQUIRED, 'Number of worker processes to start.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events processed per claimed segment.', 5)
            ->addOption('max-cycles', null, InputOption::VALUE_REQUIRED, 'Maximum coordinator cycles per worker.', 64)
        ;
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

        $this->runtime->initialize();
        $this->segments->ensureInitialized($this->runtime->projectionId());
        $this->splitUntilWorkerCount($workers);

        $this->activator->configureRun($batchSize, $maxCycles);
        $this->activator->requestAdditionalWorkers($workers);

        return $this->activator->wait($output) ? Command::SUCCESS : Command::FAILURE;
    }

    private function splitUntilWorkerCount(int $workers): void
    {
        while (count($this->segments->listSegments($this->runtime->projectionId())) < $workers) {
            $candidate = $this->largestSplittableSegment();
            if (null === $candidate) {
                return;
            }

            $this->segments->splitSegment($this->runtime->projectionId(), $candidate['bucketFrom']);
            $this->segments->reconcilePendingOperations($this->runtime->projectionId());
        }
    }

    /**
     * @return null|array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: null|string}
     */
    private function largestSplittableSegment(): ?array
    {
        $candidates = array_filter(
            $this->segments->listSegments($this->runtime->projectionId()),
            static fn (array $segment): bool => 'available' === $segment['status']
                && null === $segment['pendingOperation']
                && $segment['bucketTo'] > $segment['bucketFrom'],
        );
        usort(
            $candidates,
            static fn (array $left, array $right): int => ($right['bucketTo'] - $right['bucketFrom'])
                <=> ($left['bucketTo'] - $left['bucketFrom']),
        );

        return $candidates[0] ?? null;
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
            ->addOption('reset-schema', null, InputOption::VALUE_NONE, 'Drop harness tables before running.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $events = (int) $input->getOption('events');
        $workers = (int) $input->getOption('workers');
        $batchSize = (int) $input->getOption('batch-size');
        $maxCycles = (int) $input->getOption('max-cycles');

        if ($events < 1 || $workers < 1 || $batchSize < 1 || $maxCycles < 1) {
            throw new \InvalidArgumentException('events, workers, batch-size, and max-cycles must be greater than zero.');
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

        $seedCode = $application->find('harness:projection-seed')->run(
            new ArrayInput(['event-count' => (string) $events]),
            $output,
        );
        if (Command::SUCCESS !== $seedCode) {
            return $seedCode;
        }

        $backfillCode = $application->find('harness:projection-backfill')->run(
            new ArrayInput([
                'workers' => (string) $workers,
                '--batch-size' => (string) $batchSize,
                '--max-cycles' => (string) $maxCycles,
            ]),
            $output,
        );
        if (Command::SUCCESS !== $backfillCode) {
            return $backfillCode;
        }

        $projectedRows = (int) $this->connection->fetchOne(sprintf(
            'SELECT count(*) FROM %s',
            ProjectionCoordinationHarness::readModelTable($this->projectionPrefix),
        ));
        $pendingBuckets = (int) $this->connection->fetchOne(sprintf(
            'SELECT count(*) FROM %s WHERE counter > acknowledged_counter',
            $this->projectionPrefix.'bucket_activity',
        ));

        $output->writeln(sprintf('Projected rows: %d', $projectedRows));
        $output->writeln(sprintf('Pending buckets: %d', $pendingBuckets));
        $output->writeln(sprintf(
            'Tables were left in Postgres. event_prefix=%s projection_prefix=%s',
            $this->eventStorePrefix,
            $this->projectionPrefix,
        ));

        return $projectedRows === $events && 0 === $pendingBuckets
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}

final class ProjectionWorkerCommand extends Command
{
    public function __construct(private readonly ProjectionCoordinator $coordinator)
    {
        parent::__construct('harness:projection-worker');
    }

    protected function configure(): void
    {
        $this
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED, 'Worker identifier.', gethostname() ?: 'worker')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events processed per claimed segment.', 5)
            ->addOption('max-cycles', null, InputOption::VALUE_REQUIRED, 'Maximum coordinator cycles.', 64)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = (string) $input->getOption('worker-id');
        $result = $this->coordinator->run(
            $workerId,
            (int) $input->getOption('batch-size'),
            (int) $input->getOption('max-cycles'),
        );

        $output->writeln(sprintf(
            'worker=%s lease=%s processed=%d acknowledged=%d requested=%d',
            $workerId,
            $result->acquiredLease ? 'yes' : 'no',
            $result->processedEvents,
            $result->acknowledgedBuckets,
            $result->requestedWorkerActivations,
        ));

        return Command::SUCCESS;
    }
}

final class ProcessProjectionActivator implements ProjectionActivatorInterface
{
    /** @var list<Process> */
    private array $processes = [];

    private int $sequence = 0;
    private int $batchSize = 5;
    private int $maxCycles = 64;

    public function __construct(
        private readonly string $consolePath,
        private readonly string $dsn,
        private readonly string $eventStorePrefix,
        private readonly string $projectionPrefix,
    ) {}

    public function configureRun(int $batchSize, int $maxCycles): void
    {
        $this->batchSize = $batchSize;
        $this->maxCycles = $maxCycles;
    }

    public function signalWorkAvailable(): void
    {
        $this->requestAdditionalWorkers(1);
    }

    public function requestAdditionalWorkers(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            ++$this->sequence;
            $workerId = sprintf('process-worker-%02d', $this->sequence);
            $process = new Process(
                [
                    PHP_BINARY,
                    $this->consolePath,
                    'harness:projection-worker',
                    '--worker-id='.$workerId,
                    '--batch-size='.$this->batchSize,
                    '--max-cycles='.$this->maxCycles,
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
