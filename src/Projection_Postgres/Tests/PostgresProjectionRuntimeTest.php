<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;

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

final class PostgresProjectionRuntimeTest extends PostgresProjectionTestCase
{
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
            $tableName = $projectionPrefix . 'course_names_view';

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

            self::assertSame(2, $runner->runOnce($runtime, 'worker-1'));
            self::assertSame(0, $runner->runOnce($runtime, 'worker-1'));

            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT course_id, name FROM %s ORDER BY course_id ASC', $tableName),
            );

            self::assertSame([
                ['course_id' => 'course-1', 'name' => 'Math 101'],
                ['course_id' => 'course-2', 'name' => 'Physics 201'],
            ], $rows);

            $segment = $segmentRepository->readCurrentState('course_names', 0);

            self::assertSame(2, $segment->checkpoint->gapFloor->value);
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
            $tableName = $projectionPrefix . 'course_names_view';

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

            self::assertSame(1, $runner->runOnce($runtime, 'worker-1'));

            $store->append([
                new Event(
                    eventType: 'course.renamed',
                    payload: ['courseId' => 'course-1', 'name' => 'Advanced Math'],
                    domainIds: ['courseId' => 'course-1'],
                ),
            ]);

            self::assertSame(1, $runner->runOnce($runtime, 'worker-1'));
            self::assertSame(0, $runner->runOnce($runtime, 'worker-1'));

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
        $this->withPostgresProjectionRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            string $eventStorePrefix,
            string $projectionPrefix,
        ): void {
            $segmentRepository->ensureInitialized('course_names');

            $segmentRepository->claimSegment('course_names', 'worker-stale', leaseDurationSeconds: 0);

            $connection->executeStatement(
                "UPDATE {$projectionPrefix}segments SET lease_expires_at = now() - interval '1 second' WHERE projection_id = 'course_names'",
            );

            $recovered = $segmentRepository->recoverExpiredLeases('course_names');

            self::assertSame(1, $recovered);

            $segment = $segmentRepository->claimSegment('course_names', 'worker-new');

            self::assertNotNull($segment);
            self::assertSame('active', $segment->status);
        });
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
            $tableName = $projectionPrefix . 'course_names_view';

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

            $runner->runOnce($runtime, 'worker-1');
            $segmentRepository->splitSegment('course_names', 0);
            $segmentRepository->reconcilePendingOperations('course_names');

            $segments = $segmentRepository->listSegments('course_names');

            self::assertCount(2, $segments);
            self::assertSame(0, $segments[0]['bucketFrom']);
            self::assertSame(127, $segments[0]['bucketTo']);
            self::assertSame('available', $segments[0]['status']);
            self::assertSame(128, $segments[1]['bucketFrom']);
            self::assertSame(255, $segments[1]['bucketTo']);
            self::assertSame('available', $segments[1]['status']);
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
            $tableName = $projectionPrefix . 'course_names_view';

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

            $runner->runOnce($runtime, 'worker-1');
            $segmentRepository->splitSegment('course_names', 0);
            $segmentRepository->reconcilePendingOperations('course_names');
            $segmentRepository->requestMerge('course_names', 0, 255);

            $segments = $segmentRepository->listSegments('course_names');

            self::assertCount(2, $segments);

            foreach ($segments as $segment) {
                self::assertSame('drained', $segment['status']);
                self::assertSame('merge', $segment['pendingOperation']);
            }

            $segmentRepository->reconcilePendingOperations('course_names');

            $segments = $segmentRepository->listSegments('course_names');

            self::assertCount(1, $segments);
            self::assertSame(0, $segments[0]['bucketFrom']);
            self::assertSame(255, $segments[0]['bucketTo']);
            self::assertSame('available', $segments[0]['status']);
        });
    }

    public function test_draining_split_is_left_for_reconciliation_after_the_worker_finishes(): void
    {
        $this->withPostgresProjectionRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            string $eventStorePrefix,
            string $projectionPrefix,
        ): void {
            $tableName = $projectionPrefix . 'course_names_view';

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

            $runner->runOnce($runtime, 'worker-1');
            $segmentRepository->splitSegment('course_names', 0);

            $segments = $segmentRepository->listSegments('course_names');

            self::assertCount(1, $segments);
            self::assertSame('drained', $segments[0]['status']);
            self::assertSame('split', $segments[0]['pendingOperation']);

            $segmentRepository->reconcilePendingOperations('course_names');

            $segments = $segmentRepository->listSegments('course_names');

            self::assertCount(2, $segments);
            self::assertSame(0, $segments[0]['bucketFrom']);
            self::assertSame(128, $segments[1]['bucketFrom']);
        });
    }
}
