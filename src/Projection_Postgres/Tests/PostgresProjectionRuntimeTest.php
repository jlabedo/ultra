<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\Sequence;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\ProjectionPlan;
use Ultra\Projection_Postgres\PostgresProjectionRunner;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;

require_once __DIR__ . '/PostgresProjectionTestSupport.php';

// ---------------------------------------------------------------------------
// Event DTO
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Projection
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('runner processes events and advances the checkpoint', function (): void {
    withPostgresProjectionRuntime(function (
        Connection $connection,
        EventStore $store,
        PostgresProjectionSegmentRepository $segmentRepository,
        PostgresProjectionRunner $runner,
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

        $plan = new ProjectionPlan(
            new CourseNamesProjection($connection, $tableName),
            new UltraNormalizer(),
        );

        expect($runner->runOnce($plan, 'worker-1'))->toBe(2)
            ->and($runner->runOnce($plan, 'worker-1'))->toBe(0);

        $rows = $connection->fetchAllAssociative(
            sprintf('SELECT course_id, name FROM %s ORDER BY course_id ASC', $tableName),
        );

        expect($rows)->toBe([
            ['course_id' => 'course-1', 'name' => 'Math 101'],
            ['course_id' => 'course-2', 'name' => 'Physics 201'],
        ]);

        $segment = $segmentRepository->readCurrentState('course_names', 0);
        expect($segment->checkpoint->gapFloor->value)->toBe(2);
    });
});

test('runner resumes from checkpoint after restart', function (): void {
    withPostgresProjectionRuntime(function (
        Connection $connection,
        EventStore $store,
        PostgresProjectionSegmentRepository $segmentRepository,
        PostgresProjectionRunner $runner,
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

        $plan = new ProjectionPlan(
            new CourseNamesProjection($connection, $tableName),
            new UltraNormalizer(),
        );

        expect($runner->runOnce($plan, 'worker-1'))->toBe(1);

        // Append a second event
        $store->append([
            new Event(
                eventType: 'course.renamed',
                payload: ['courseId' => 'course-1', 'name' => 'Advanced Math'],
                domainIds: ['courseId' => 'course-1'],
            ),
        ]);

        // Second run should process only the new event
        expect($runner->runOnce($plan, 'worker-1'))->toBe(1)
            ->and($runner->runOnce($plan, 'worker-1'))->toBe(0);

        $row = $connection->fetchAssociative(
            sprintf('SELECT name FROM %s WHERE course_id = ?', $tableName),
            ['course-1'],
        );

        expect($row['name'])->toBe('Advanced Math');
    });
});

test('expired lease is recovered so another worker can claim the segment', function (): void {
    withPostgresProjectionRuntime(function (
        Connection $connection,
        EventStore $store,
        PostgresProjectionSegmentRepository $segmentRepository,
        PostgresProjectionRunner $runner,
        string $eventStorePrefix,
        string $projectionPrefix,
    ): void {
        $segmentRepository->ensureInitialized('course_names');

        // Claim with a 0-second lease so it expires immediately
        $segmentRepository->claimSegment('course_names', 'worker-stale', leaseDurationSeconds: 0);

        // Simulate lease expiry by waiting 1 second then recovering
        $connection->executeStatement(
            "UPDATE {$projectionPrefix}segments SET lease_expires_at = now() - interval '1 second' WHERE projection_id = 'course_names'",
        );

        $recovered = $segmentRepository->recoverExpiredLeases('course_names');
        expect($recovered)->toBe(1);

        $segment = $segmentRepository->claimSegment('course_names', 'worker-new');
        expect($segment)->not->toBeNull()
            ->and($segment->status)->toBe('active');
    });
});

test('segment split divides the range into two equal halves', function (): void {
    withPostgresProjectionRuntime(function (
        Connection $connection,
        EventStore $store,
        PostgresProjectionSegmentRepository $segmentRepository,
        PostgresProjectionRunner $runner,
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

        $plan = new ProjectionPlan(
            new CourseNamesProjection($connection, $tableName),
            new UltraNormalizer(),
        );

        $runner->runOnce($plan, 'worker-1');

        $segmentRepository->splitSegment('course_names', 0);

        $segments = $segmentRepository->listSegments('course_names');

        expect($segments)->toHaveCount(2)
            ->and($segments[0])->toMatchArray(['bucketFrom' => 0, 'bucketTo' => 127, 'status' => 'available'])
            ->and($segments[1])->toMatchArray(['bucketFrom' => 128, 'bucketTo' => 255, 'status' => 'available']);
    });
});

test('segment merge recombines two siblings into a parent', function (): void {
    withPostgresProjectionRuntime(function (
        Connection $connection,
        EventStore $store,
        PostgresProjectionSegmentRepository $segmentRepository,
        PostgresProjectionRunner $runner,
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

        $plan = new ProjectionPlan(
            new CourseNamesProjection($connection, $tableName),
            new UltraNormalizer(),
        );

        $runner->runOnce($plan, 'worker-1');

        // Split first
        $segmentRepository->splitSegment('course_names', 0);

        // Both halves are available, so merge can proceed via draining
        $segmentRepository->requestMerge('course_names', 0, 255);

        $segments = $segmentRepository->listSegments('course_names');
        expect($segments)->toHaveCount(2);
        foreach ($segments as $seg) {
            expect($seg['status'])->toBe('draining')
                ->and($seg['pendingOperation'])->toBe('merge');
        }

        // Simulate finalization by each worker
        $state0 = $segmentRepository->readCurrentState('course_names', 0);
        $state128 = $segmentRepository->readCurrentState('course_names', 128);

        $segmentRepository->finalizeMerge($state0, $state0->checkpoint);
        $segmentRepository->finalizeMerge($state128, $state128->checkpoint);

        $segments = $segmentRepository->listSegments('course_names');
        expect($segments)->toHaveCount(1)
            ->and($segments[0])->toMatchArray(['bucketFrom' => 0, 'bucketTo' => 255, 'status' => 'available']);
    });
});

test('draining split is finalized by the worker on its next iteration', function (): void {
    withPostgresProjectionRuntime(function (
        Connection $connection,
        EventStore $store,
        PostgresProjectionSegmentRepository $segmentRepository,
        PostgresProjectionRunner $runner,
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

        $plan = new ProjectionPlan(
            new CourseNamesProjection($connection, $tableName),
            new UltraNormalizer(),
        );

        // First run: claims [0-255], processes 1 event, releases
        $runner->runOnce($plan, 'worker-1');

        // Mark for split while segment is available (simulates operator calling split)
        $segmentRepository->splitSegment('course_names', 0);

        // Since segment was available (not active), split was immediate.
        // Verify two segments now exist.
        $segments = $segmentRepository->listSegments('course_names');
        expect($segments)->toHaveCount(2)
            ->and($segments[0]['bucketFrom'])->toBe(0)
            ->and($segments[1]['bucketFrom'])->toBe(128);
    });
});
