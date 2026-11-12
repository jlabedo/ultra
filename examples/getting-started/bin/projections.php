<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\Connection;
use GettingStarted\Database;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\Event;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionActivityEventStore;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;

#[EventAttribute('tutorial.course.named')]
final readonly class CourseNamed
{
    public function __construct(public string $courseId, public string $name) {}
}

#[Projection(name: 'tutorial.course_names', partitionBy: 'courseId')]
final readonly class CourseNames
{
    public function __construct(private Connection $connection) {}

    #[ProjectionHandler]
    public function named(CourseNamed $event): void
    {
        $this->connection->executeStatement(
            'INSERT INTO tutorial_course_names (id, name) VALUES (?, ?) ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name',
            [$event->courseId, $event->name],
        );
    }

    #[ProjectionResetter]
    public function reset(): void
    {
        $this->connection->executeStatement('DELETE FROM tutorial_course_names');
    }
}

$mode = $argv[1] ?? '';
if (!in_array($mode, ['append', 'work', 'rebuild'], true)) {
    throw new InvalidArgumentException('Run with append, work or rebuild.');
}
$connection = Database::connect();
$normalizer = new UltraNormalizer(dirname(__DIR__) . '/var/projection-normalizers', 'GettingStartedProjectionGenerated');
$runtime = new ProjectionRuntime(new CourseNames($connection), $normalizer);
$catalog = new ProjectionCatalog($runtime);
$implementation = new PostgresProjectionImplementation($connection, 'tutorial_projection_');
$activity = new ProjectionActivity($implementation->createDirtyKeyTracker());
$lease = $implementation->createCoordinatorLease();
$activator = new InMemoryProjectionActivator();
$recorder = new ProjectionActivityRecorder($catalog, $activity, $lease, $activator);
$store = new ProjectionActivityEventStore(new PostgresEventStoreQueryStreams($connection, 'tutorial_es_'), $recorder);
$runner = new ProjectionRunner($store, $implementation->createSegmentRepository(), $implementation->createTransactionManager(), $activity);

if ('append' === $mode) {
    $id = 'course-' . bin2hex(random_bytes(4));
    $connection->transactional(static function () use ($store, $id): void {
        $store->append([new Event('tutorial.course.named', ['courseId' => $id, 'name' => 'Ultra basics'], ['courseId' => $id])]);
    });
    echo "Event and projection activity committed.\n";
} elseif ('work' === $mode) {
    $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $lease, $activator);
    $result = $coordinator->run('tutorial-worker', batchSize: 1);
    echo 'Projected ' . $result->processedEvents . " event(s).\n";
} else {
    $count = $runner->replayFromBeginning($runtime, 'tutorial-rebuild', limit: 1);
    echo 'Rebuilt from ' . $count . " event(s).\n";
}
echo 'Read-model rows: ' . $connection->fetchOne('SELECT COUNT(*) FROM tutorial_course_names') . "\n";
