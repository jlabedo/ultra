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
use Ultra\Events\Attribute\Event as EventAttribute;
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
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionTopology;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentStore;
use Ultra\Projection_Postgres\PostgresProjectionTransactionManager;
use Ultra\Testing\PostgresTestSupport;

#[EventAttribute('harness.course.created')]
final readonly class HarnessCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: ProjectionBackfillHarness::PROJECTION_ID, partitionBy: 'courseId')]
final readonly class HarnessCourseNamesProjection
{
    private MappedRowSet $table;

    public function __construct(
        private Connection $connection,
        private string $tableName,
    ) {
        $this->table = new MappedRowSet(
            new PostgresMappedRowSetAdapter($this->connection, $this->tableName, 'course_id'),
        );
    }

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

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $this->tableName));
    }

    #[ProjectionHandler]
    public function onCourseCreated(HarnessCourseCreated $event): void
    {
        $this->table->withId($event->courseId)
            ->upsert()
            ->setColumn('name', $event->name)
        ;
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        $this->table->flush();
        $this->table->clear();
    }
}

final class ProjectionBackfillHarness
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
        $segments = new PostgresProjectionSegmentStore($connection, $projectionPrefix);
        $transactionManager = new PostgresProjectionTransactionManager($connection);
        $runtime = self::runtime($connection, $projectionPrefix);
        self::warmNormalizer();
        $catalog = new ProjectionCatalog($runtime);
        $runner = new ProjectionRunner($eventStore, $segments, $transactionManager);
        $topology = new ProjectionTopology($eventStore, $segments, $segments, $transactionManager);
        $consolePath ??= __DIR__.'/projection-coordination-harness';

        $application = new Application('Projection Backfill Harness');
        $application->setAutoExit(false);
        $processActivator = new ProcessProjectionBackfillActivator($consolePath, $dsn, $eventStorePrefix, $projectionPrefix);

        $application->addCommands([
            new CreateProjectionHarnessSchemaCommand($connection, $eventStorePrefix, $projectionPrefix),
            new SeedProjectionEventsCommand($eventStore),
            new BackfillProjectionCommand($catalog, $runner, $topology, $processActivator),
            new ResetProjectionCommand($catalog, $runner),
            new RunProjectionHarnessCommand($connection, $eventStorePrefix, $projectionPrefix),
            new ProjectionBackfillWorkerCommand($catalog, $runner),
        ]);

        return $application;
    }

    public static function connection(string $dsn): Connection
    {
        return PostgresTestSupport::sharedConnection($dsn);
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
        ];
    }

    private static function runtime(Connection $connection, string $projectionPrefix): ProjectionRuntime
    {
        return new ProjectionRuntime(
            new HarnessCourseNamesProjection($connection, self::readModelTable($projectionPrefix)),
            new UltraNormalizer(),
        );
    }

    private static function warmNormalizer(): void
    {
        (new UltraNormalizer())->fromScalar(
            ['courseId' => 'warmup', 'name' => 'Warmup'],
            HarnessCourseCreated::class,
        );
    }

    public static function elapsedMs(float $startedAt): string
    {
        return sprintf('%.2fms', (microtime(true) - $startedAt) * 1000);
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
        $schemas = ProjectionBackfillHarness::schemas($this->eventStorePrefix, $this->projectionPrefix);

        if ($reset) {
            foreach (array_reverse($schemas) as $schema) {
                foreach ($schema->dropStatements() as $statement) {
                    $this->connection->executeStatement($statement);
                }
            }

            $this->connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s',
                ProjectionBackfillHarness::readModelTable($this->projectionPrefix),
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
        $startedAt = microtime(true);
        $this->createSchema((bool) $input->getOption('reset'));
        $output->writeln(sprintf(
            'Projection harness schema is ready in %s. event_prefix=%s projection_prefix=%s',
            ProjectionBackfillHarness::elapsedMs($startedAt),
            $this->eventStorePrefix,
            $this->projectionPrefix,
        ));

        return Command::SUCCESS;
    }
}

final class SeedProjectionEventsCommand extends Command
{
    private const int BATCH_SIZE = 1000;

    public function __construct(private readonly PostgresEventStoreQueryStreams $eventStore)
    {
        parent::__construct('harness:projection-seed');
    }

    protected function configure(): void
    {
        $this->addArgument('event-count', InputArgument::OPTIONAL, 'Number of course-created events to seed.', 32);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = microtime(true);
        $eventCount = (int) $input->getArgument('event-count');
        if ($eventCount < 1) {
            throw new \InvalidArgumentException('event-count must be greater than zero.');
        }

        $seeded = 0;
        $events = [];
        for ($i = 1; $i <= $eventCount; ++$i) {
            $courseId = sprintf('course-%03d', $i);
            $events[] = new Event(
                eventType: 'harness.course.created',
                payload: ['courseId' => $courseId, 'name' => sprintf('Course %03d', $i)],
                domainIds: ['courseId' => $courseId],
            );

            if (self::BATCH_SIZE === count($events)) {
                $this->eventStore->append($events);
                $seeded += count($events);
                $events = [];
            }
        }

        if ([] !== $events) {
            $this->eventStore->append($events);
            $seeded += count($events);
        }

        $output->writeln(sprintf(
            'Seeded %d projection events in batches of %d in %s.',
            $seeded,
            self::BATCH_SIZE,
            ProjectionBackfillHarness::elapsedMs($startedAt),
        ));

        return Command::SUCCESS;
    }
}

final class BackfillProjectionCommand extends Command
{
    public function __construct(
        private readonly ProjectionCatalog $catalog,
        private readonly ProjectionRunner $runner,
        private readonly ProjectionTopology $topology,
        private readonly ProcessProjectionBackfillActivator $activator,
    ) {
        parent::__construct('harness:projection-backfill');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workers', InputArgument::REQUIRED, 'Number of worker processes and canonical segments.')
            ->addOption('projection-id', null, InputOption::VALUE_REQUIRED, 'Projection identifier.', ProjectionBackfillHarness::PROJECTION_ID)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events processed per claimed segment.', 5)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = microtime(true);
        $workers = (int) $input->getArgument('workers');
        if ($workers < 1) {
            throw new \InvalidArgumentException('workers must be greater than zero.');
        }

        $batchSize = (int) $input->getOption('batch-size');
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batch-size must be greater than zero.');
        }

        $projectionId = (string) $input->getOption('projection-id');
        $runtime = $this->catalog->runtime($projectionId);

        $this->runner->ensureInitialized($runtime);
        $this->runner->recoverExpiredClaims($projectionId);
        $segments = $this->topology->reshapeToCanonicalSegments($runtime, $workers, 'process-backfill', $batchSize);

        foreach ($segments as $segment) {
            $this->activator->activateWorker(
                $projectionId,
                $segment->bucketFrom,
                sprintf('process-backfill-worker-%d', $segment->bucketFrom),
                $batchSize,
            );
        }

        $successful = $this->activator->wait($output);
        $output->writeln(sprintf(
            'Backfill completed with %d worker process(es) in %s.',
            count($segments),
            ProjectionBackfillHarness::elapsedMs($startedAt),
        ));

        return $successful ? Command::SUCCESS : Command::FAILURE;
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
            ProjectionBackfillHarness::PROJECTION_ID,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = microtime(true);
        $projectionId = (string) $input->getOption('projection-id');

        $this->runner->deleteProjection($this->catalog->runtime($projectionId));

        $output->writeln(sprintf('Projection "%s" was reset in %s.', $projectionId, ProjectionBackfillHarness::elapsedMs($startedAt)));

        return Command::SUCCESS;
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
            ->addOption('reset-schema', null, InputOption::VALUE_NONE, 'Drop harness tables before running.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = microtime(true);
        $events = (int) $input->getOption('events');
        $workers = (int) $input->getOption('workers');
        $batchSize = (int) $input->getOption('batch-size');

        if ($events < 1 || $workers < 1 || $batchSize < 1) {
            throw new \InvalidArgumentException('events, workers, and batch-size must be greater than zero.');
        }

        $application = $this->getApplication();
        if (!$application instanceof Application) {
            throw new \LogicException('The harness test command must be registered in an application.');
        }

        $schema = $application->find('harness:projection-schema:create');
        if (!$schema instanceof CreateProjectionHarnessSchemaCommand) {
            throw new \LogicException('Schema command is not registered.');
        }

        $schemaStartedAt = microtime(true);
        $schema->createSchema((bool) $input->getOption('reset-schema'));
        $output->writeln(sprintf('Schema ready in %s.', ProjectionBackfillHarness::elapsedMs($schemaStartedAt)));

        $seedStartedAt = microtime(true);
        $seedCode = $application->find('harness:projection-seed')->run(
            new ArrayInput(['event-count' => (string) $events]),
            $output,
        );
        $output->writeln(sprintf('Seed step completed in %s.', ProjectionBackfillHarness::elapsedMs($seedStartedAt)));
        if (Command::SUCCESS !== $seedCode) {
            return $seedCode;
        }

        $backfillStartedAt = microtime(true);
        $backfillCode = $application->find('harness:projection-backfill')->run(
            new ArrayInput([
                'workers' => (string) $workers,
                '--batch-size' => (string) $batchSize,
            ]),
            $output,
        );
        $output->writeln(sprintf('Backfill step completed in %s.', ProjectionBackfillHarness::elapsedMs($backfillStartedAt)));
        if (Command::SUCCESS !== $backfillCode) {
            return $backfillCode;
        }

        $verificationStartedAt = microtime(true);
        $projectedRows = (int) $this->connection->fetchOne(sprintf(
            'SELECT count(*) FROM %s',
            ProjectionBackfillHarness::readModelTable($this->projectionPrefix),
        ));

        $output->writeln(sprintf('Projected rows: %d verified in %s.', $projectedRows, ProjectionBackfillHarness::elapsedMs($verificationStartedAt)));
        $output->writeln(sprintf(
            'Tables were left in Postgres. event_prefix=%s projection_prefix=%s',
            $this->eventStorePrefix,
            $this->projectionPrefix,
        ));
        $output->writeln(sprintf('Harness projection test completed in %s.', ProjectionBackfillHarness::elapsedMs($startedAt)));

        return $projectedRows === $events
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}

final class ProjectionBackfillWorkerCommand extends Command
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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = microtime(true);
        $workerId = (string) $input->getOption('worker-id');
        $projectionId = (string) $input->getOption('projection-id');
        $bucketFrom = (int) $input->getOption('bucket-from');
        $runtime = $this->catalog->runtime($projectionId);
        $batchSize = (int) $input->getOption('batch-size');
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batch-size must be greater than zero.');
        }

        $processedEvents = 0;
        $caughtUp = false;
        do {
            $result = $this->runner->backfillClaimedOnce($runtime, $projectionId, $bucketFrom, $workerId, $batchSize);
            if (!$result->claimedSegment) {
                break;
            }

            $processedEvents += $result->processedEvents;
            $caughtUp = $result->caughtUp;
        } while (!$caughtUp);

        $output->writeln(sprintf(
            'worker=%s projection=%s bucket_from=%d caught_up=%s processed=%d elapsed=%s',
            $workerId,
            $projectionId,
            $bucketFrom,
            $caughtUp ? 'yes' : 'no',
            $processedEvents,
            ProjectionBackfillHarness::elapsedMs($startedAt),
        ));

        return $caughtUp ? Command::SUCCESS : Command::FAILURE;
    }
}

final class ProcessProjectionBackfillActivator
{
    /** @var list<Process> */
    private array $processes = [];

    public function __construct(
        private readonly string $consolePath,
        private readonly string $dsn,
        private readonly string $eventStorePrefix,
        private readonly string $projectionPrefix,
    ) {}

    public function activateWorker(string $projectionId, int $bucketFrom, string $workerId, int $batchSize): void
    {
        $process = new Process(
            [
                PHP_BINARY,
                $this->consolePath,
                'harness:projection-worker',
                '--worker-id='.$workerId,
                '--projection-id='.$projectionId,
                '--bucket-from='.(string) $bucketFrom,
                '--batch-size='.(string) $batchSize,
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
