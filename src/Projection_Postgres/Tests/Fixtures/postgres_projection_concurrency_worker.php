<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivityRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;

use function Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestStoreForImplementation;

require_once __DIR__.'/../../../../vendor/autoload.php';
require_once __DIR__.'/../../../EventStore_Postgres/Tests/Support/PostgresTestSupport.php';

/**
 * @param array<string, mixed> $message
 */
function emitProjectionWorkerMessage(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR).PHP_EOL);
    fflush(STDOUT);
}

#[EventAttribute('course.created')]
final readonly class ConcurrencyCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'course_names', partitionBy: 'courseId')]
final class ConcurrencyCourseNamesProjection
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
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
    public function onCourseCreated(ConcurrencyCourseCreated $event): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (course_id, name) VALUES (?, ?)
                 ON CONFLICT (course_id) DO UPDATE SET name = EXCLUDED.name',
                $this->tableName,
            ),
            [$event->courseId, $event->name],
        );
    }
}

final class BarrierEventStore implements EventStore
{
    private static bool $emittedBarrier = false;

    public function __construct(
        private EventStore $inner,
        private bool $pauseBeforeFirstLoad,
    ) {}

    public function load(
        StreamQuery $query,
        ?\Ultra\EventStore\Checkpoint $from = null,
        ?\Ultra\EventStore\Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        if ($this->pauseBeforeFirstLoad && !self::$emittedBarrier) {
            self::$emittedBarrier = true;
            emitProjectionWorkerMessage([
                'type' => 'event',
                'name' => 'segment_claimed',
            ]);

            $releaseSignal = fgets(STDIN);
            if (false === $releaseSignal || 'release' !== trim($releaseSignal)) {
                throw new RuntimeException('Expected "release" on STDIN.');
            }
        }

        return $this->inner->load($query, $from, $to, $batchSize);
    }

    public function append(array $events, \Ultra\EventStore\AppendCondition ...$appendConditions): void
    {
        $this->inner->append($events, ...$appendConditions);
    }
}

$configEncoded = getenv('ULTRA_WORKER_CONFIG');
if (false === $configEncoded || '' === $configEncoded) {
    fwrite(STDERR, "Missing ULTRA_WORKER_CONFIG\n");

    exit(64);
}

$decoded = base64_decode($configEncoded, true);
if (false === $decoded) {
    fwrite(STDERR, "Invalid ULTRA_WORKER_CONFIG payload\n");

    exit(64);
}

$config = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
$connection = postgresTestConnection($config['dsn']);

try {
    $store = postgresTestStoreForImplementation(
        $connection,
        $config['eventStorePrefix'],
        'query-streams',
    );
    $runner = new ProjectionRunner(
        new BarrierEventStore($store, (bool) ($config['pauseBeforeFirstLoad'] ?? false)),
        new PostgresProjectionSegmentRepository($connection, $config['projectionPrefix']),
    );
    $runtime = new ProjectionRuntime(
        new ConcurrencyCourseNamesProjection($connection, $config['tableName']),
        new UltraNormalizer(),
    );
    $coordinator = new ProjectionCoordinator(
        new ProjectionCatalog($runtime),
        $runner,
        new PostgresProjectionBucketActivityRepository($connection, $config['projectionPrefix']),
        new PostgresProjectionCoordinatorLeaseRepository($connection, $config['projectionPrefix']),
        new InMemoryProjectionActivator(),
    );

    emitProjectionWorkerMessage([
        'type' => 'event',
        'name' => 'starting',
        'workerId' => $config['workerId'],
    ]);

    $result = $coordinator->run($config['workerId'], batchSize: 100, maxCycles: 8);

    emitProjectionWorkerMessage([
        'type' => 'result',
        'status' => 'ok',
        'acquiredLease' => $result->acquiredLease,
        'processedEvents' => $result->processedEvents,
        'acknowledgedBuckets' => $result->acknowledgedBuckets,
        'requestedWorkerActivations' => $result->requestedWorkerActivations,
    ]);

    exit(0);
} catch (Throwable $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    emitProjectionWorkerMessage([
        'type' => 'result',
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ]);

    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);

    exit(1);
} finally {
    $connection->close();
}
