<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Signal_Postgres\PostgresSignalSchema;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Time\Duration;

#[EventAttribute('course.created')]
final readonly class CourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[EventAttribute('course.renamed')]
final readonly class CourseRenamed
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'course_names', partitionBy: 'courseId')]
final class CourseNamesProjection
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
    public function onCourseCreated(CourseCreated $event): void
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

    #[ProjectionHandler]
    public function onCourseRenamed(CourseRenamed $event): void
    {
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET name = ? WHERE course_id = ?', $this->tableName),
            [$event->name, $event->courseId],
        );
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class PostgresProjectionRuntimeTest extends PostgresProjectionTestCase
{
    public function test_signal_poller_processes_projection_work_with_real_postgres_signal_store(): void
    {
        $connection = PostgresTestSupport::sharedConnection($this->postgresDsn());
        $eventStorePrefix = PostgresTestSupport::uniquePrefix();
        $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
        $signalPrefix = 'ultra_signal_'.bin2hex(random_bytes(4)).'_';
        $eventSchema = PostgresTestSupport::schemaForImplementation($eventStorePrefix, 'query-streams');
        $projectionSchema = new PostgresProjectionSegmentSchema($projectionPrefix);
        $signalSchema = new PostgresSignalSchema($signalPrefix);
        $tableName = $projectionPrefix.'course_names_view';

        try {
            foreach ($eventSchema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            foreach ($projectionSchema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            foreach ($signalSchema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $store = PostgresTestSupport::storeForImplementation($connection, $eventStorePrefix, 'query-streams');
            $container = $this->projectionUltraLight(
                $store,
                new PostgresProjectionImplementation($connection, $projectionPrefix),
                signalTablePrefix: $signalPrefix,
            )
                ->withService($connection, Connection::class)
                ->withService(new CourseNamesProjection($connection, $tableName))
                ->boot()
            ;

            $container->eventStore()->append([
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => 'course-1', 'name' => 'Math 101'],
                    domainIds: ['courseId' => 'course-1'],
                ),
            ]);

            self::assertSame(1, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $signalPrefix.'signals')));

            self::assertSame(1, $container->signalPoller()->pollOnce());

            $rows = $connection->fetchAllAssociative(sprintf('SELECT course_id, name FROM %s', $tableName));

            self::assertSame([
                ['course_id' => 'course-1', 'name' => 'Math 101'],
            ], $rows);
            self::assertSame(0, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $signalPrefix.'signals')));
        } finally {
            PostgresTestSupport::resetSharedConnection($connection);

            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tableName));

            foreach ($signalSchema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            foreach ($projectionSchema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            foreach ($eventSchema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }

    public function test_runner_processes_events_and_advances_the_checkpoint(): void
    {
        $this->withPostgresProjectionRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            string $eventStorePrefix,
            string $projectionPrefix,
        ): void {
            $tableName = $projectionPrefix.'course_names_view';

            $store->append([
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => 'course-1', 'name' => 'Math 101'],
                    domainIds: ['courseId' => 'course-1'],
                ),
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => 'course-2', 'name' => 'Physics 201'],
                    domainIds: ['courseId' => 'course-2'],
                ),
            ]);

            $runtime = new ProjectionRuntime(
                new CourseNamesProjection($connection, $tableName),
                new UltraNormalizer(),
            );

            $runner->ensureInitialized($runtime);
            self::assertNotNull($runner->claimSegment('course_names', 0, 'worker-1'));

            $result = $runner->backfillClaimedOnce($runtime, 'course_names', 0, 'worker-1');

            self::assertSame(2, $result->processedEvents);
            self::assertTrue($result->caughtUp);

            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT course_id, name FROM %s ORDER BY course_id ASC', $tableName),
            );

            self::assertSame([
                ['course_id' => 'course-1', 'name' => 'Math 101'],
                ['course_id' => 'course-2', 'name' => 'Physics 201'],
            ], $rows);

            self::assertNull($segmentRepository->listSegments('course_names')[0]->workerId);
        });
    }

    public function test_runner_resumes_from_checkpoint_after_restart(): void
    {
        $this->withPostgresProjectionRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            string $eventStorePrefix,
            string $projectionPrefix,
        ): void {
            $tableName = $projectionPrefix.'course_names_view';

            $store->append([
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => 'course-1', 'name' => 'Math 101'],
                    domainIds: ['courseId' => 'course-1'],
                ),
            ]);

            $runtime = new ProjectionRuntime(
                new CourseNamesProjection($connection, $tableName),
                new UltraNormalizer(),
            );

            $runner->ensureInitialized($runtime);
            self::assertNotNull($runner->claimSegment('course_names', 0, 'worker-1'));
            self::assertSame(1, $runner->backfillClaimedOnce($runtime, 'course_names', 0, 'worker-1')->processedEvents);

            $store->append([
                new Event(
                    eventType: 'course.renamed',
                    payload: ['courseId' => 'course-1', 'name' => 'Advanced Math'],
                    domainIds: ['courseId' => 'course-1'],
                ),
            ]);

            self::assertNotNull($runner->claimSegment('course_names', 0, 'worker-1'));
            self::assertSame(1, $runner->backfillClaimedOnce($runtime, 'course_names', 0, 'worker-1')->processedEvents);

            $row = $connection->fetchAssociative(
                sprintf('SELECT name FROM %s WHERE course_id = ?', $tableName),
                ['course-1'],
            );

            self::assertIsArray($row);
            self::assertSame('Advanced Math', $row['name']);
        });
    }

    public function test_expired_lease_is_recovered_so_another_worker_can_claim_the_segment(): void
    {
        $clock = $this->staticClock('2024-01-01T00:00:00+00:00');

        $this->withPostgresProjectionRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            string $eventStorePrefix,
            string $projectionPrefix,
        ) use ($clock): void {
            $segmentRepository->ensureInitialized('course_names');

            self::assertNotNull($segmentRepository->claimSegment('course_names', 0, 'worker-stale', Duration::seconds(30)));
            $clock->sleep(Duration::seconds(31));

            $recovered = $segmentRepository->recoverExpiredClaims('course_names');

            self::assertSame(1, $recovered);

            $segment = $segmentRepository->claimSegment('course_names', 0, 'worker-new');

            self::assertNotNull($segment);
        }, $clock);
    }

    public function test_segment_split_divides_the_range_into_two_equal_halves(): void
    {
        $this->withPostgresProjectionRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            string $eventStorePrefix,
            string $projectionPrefix,
        ): void {
            $tableName = $projectionPrefix.'course_names_view';

            $store->append([
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => 'course-1', 'name' => 'Math 101'],
                    domainIds: ['courseId' => 'course-1'],
                ),
            ]);

            $runtime = new ProjectionRuntime(
                new CourseNamesProjection($connection, $tableName),
                new UltraNormalizer(),
            );

            $runner->ensureInitialized($runtime);
            $segmentRepository->splitSegment('course_names', 0);

            $segments = $segmentRepository->listSegments('course_names');

            self::assertCount(2, $segments);
            self::assertSame(0, $segments[0]->bucketFrom);
            self::assertSame(127, $segments[0]->bucketTo);
            self::assertNull($segments[0]->workerId);
            self::assertSame(128, $segments[1]->bucketFrom);
            self::assertSame(255, $segments[1]->bucketTo);
            self::assertNull($segments[1]->workerId);
        });
    }

    public function test_segment_merge_recombines_two_siblings_into_a_parent(): void
    {
        $this->withPostgresProjectionRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            string $eventStorePrefix,
            string $projectionPrefix,
        ): void {
            $tableName = $projectionPrefix.'course_names_view';

            $store->append([
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => 'course-1', 'name' => 'Math 101'],
                    domainIds: ['courseId' => 'course-1'],
                ),
            ]);

            $runtime = new ProjectionRuntime(
                new CourseNamesProjection($connection, $tableName),
                new UltraNormalizer(),
            );

            $runner->ensureInitialized($runtime);
            $segmentRepository->splitSegment('course_names', 0);
            $runner->mergeSegments($runtime, 0, 255, 'coordinator-1');

            $segments = $segmentRepository->listSegments('course_names');

            self::assertCount(1, $segments);
            self::assertSame(0, $segments[0]->bucketFrom);
            self::assertSame(255, $segments[0]->bucketTo);
            self::assertNull($segments[0]->workerId);
        });
    }

    public function test_active_split_is_taken_from_the_worker_immediately(): void
    {
        $this->withPostgresProjectionRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            string $eventStorePrefix,
            string $projectionPrefix,
        ): void {
            $tableName = $projectionPrefix.'course_names_view';

            $store->append([
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => 'course-1', 'name' => 'Math 101'],
                    domainIds: ['courseId' => 'course-1'],
                ),
            ]);

            $runtime = new ProjectionRuntime(
                new CourseNamesProjection($connection, $tableName),
                new UltraNormalizer(),
            );

            $runner->ensureInitialized($runtime);
            $segment = $runner->claimSegment('course_names', 0, 'worker-1');
            self::assertNotNull($segment);
            $segmentRepository->splitSegment('course_names', 0);

            $segments = $segmentRepository->listSegments('course_names');

            self::assertCount(2, $segments);
            self::assertSame(0, $segments[0]->bucketFrom);
            self::assertSame(128, $segments[1]->bucketFrom);

            $segmentRepository->completeClaimedSegment($segment, Checkpoint::beginning(), 'worker-1', true);

            $segments = $segmentRepository->listSegments('course_names');

            self::assertCount(2, $segments);
            self::assertSame(0, $segments[0]->bucketFrom);
            self::assertSame(128, $segments[1]->bucketFrom);
        });
    }
}
