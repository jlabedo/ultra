<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionCoordinatorResult;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunResult;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ConcurrencyCourseNamesProjection;
use Ultra\Projection\Tests\Support\PostgresProjectionTestCase;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\PostgresProjectionTransactionManager;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/** @internal */
#[CoversNothing]
#[Group('slow')]
#[Group('projection-concurrency')]
final class ConcurrentExecutionTest extends PostgresProjectionTestCase
{
    public function test_competing_connections_can_only_claim_a_segment_once(): void
    {
        $fixture = $this->fixture('postgres');
        $fixture->segments->ensureInitialized('courses');
        $dsn = $this->postgresDsn();
        $prefix = $fixture->prefix;
        $cluster = new Cluster(timeout: 5.0);
        $workers = [];

        try {
            foreach (['worker-1', 'worker-2'] as $workerId) {
                $worker = $cluster->spawn(static function () use ($dsn, $prefix, $workerId): ?string {
                    $connection = PostgresTestSupport::connection($dsn);
                    try {
                        $segments = new PostgresProjectionSegmentRepository($connection, $prefix);
                        checkpoint('ready');

                        return $segments->claimSegment('courses', 0, $workerId)?->workerId;
                    } finally {
                        $connection->close();
                    }
                });
                $workers[] = $worker;
                $worker->awaitCheckpoint('ready');
            }

            $cluster->releaseAll('ready');
            $winners = [];
            foreach ($cluster->joinAll() as $outcome) {
                self::assertTrue($outcome->isSuccess(), $outcome->exceptionMessage());
                if (null !== $outcome->value()) {
                    $winners[] = $outcome->value();
                }
            }

            self::assertCount(1, $winners);
            self::assertSame($winners[0], $fixture->segments->listSegments('courses')[0]->workerId);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function test_leader_and_follower_can_process_different_segments_concurrently(): void
    {
        $dsn = $this->postgresDsn();

        $this->withPostgresProjectionCoordinatorRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segments,
            ProjectionRunner $runner,
            ProjectionActivity $activity,
            CoordinatorLease $leases,
            string $eventStorePrefix,
            string $projectionPrefix,
        ) use ($dsn): void {
            $tableName = $projectionPrefix.'course_names_view';
            [$leftCourseId, $rightCourseId] = self::courseIdsAcrossSplit();
            $leftBucket = BucketHasher::bucketFor($leftCourseId);
            $rightBucket = BucketHasher::bucketFor($rightCourseId);

            $connection->executeStatement(sprintf(
                'CREATE TABLE %s (course_id TEXT PRIMARY KEY, name TEXT NOT NULL)',
                $tableName,
            ));

            $store->append([
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => $leftCourseId, 'name' => 'Math 101'],
                    domainIds: ['courseId' => $leftCourseId],
                ),
                new Event(
                    eventType: 'course.created',
                    payload: ['courseId' => $rightCourseId, 'name' => 'Physics 201'],
                    domainIds: ['courseId' => $rightCourseId],
                ),
            ]);

            $segments->ensureInitialized('course_names');
            $segments->splitSegment('course_names', 0);
            $activity->bump('course_names', $leftBucket);
            $activity->bump('course_names', $rightBucket);

            $testFile = __FILE__;

            $cluster = new Cluster(timeout: 10.0);

            // Leader: claims its segment, then pauses at 'segment_claimed' before loading
            // events so the follower can concurrently claim the other segment.
            $leader = $cluster->spawn(
                static function () use ($dsn, $eventStorePrefix, $projectionPrefix, $tableName, $testFile): ProjectionCoordinatorResult {
                    require_once $testFile;

                    $connection = PostgresTestSupport::connection($dsn);
                    $innerStore = PostgresTestSupport::storeForImplementation($connection, $eventStorePrefix, 'query-streams');

                    // Inline barrier: fires once on the first load(), holding the segment
                    // claim open so the follower can grab the other segment concurrently.
                    $barrierStore = new class($innerStore) implements EventStore {
                        private bool $checkpointed = false;

                        public function __construct(private readonly EventStore $inner) {}

                        public function load(
                            StreamQuery $query,
                            ?Checkpoint $from = null,
                            ?Checkpoint $to = null,
                            int $batchSize = 1000,
                        ): LoadResult {
                            if (!$this->checkpointed) {
                                $this->checkpointed = true;
                                checkpoint('segment_claimed');
                            }

                            return $this->inner->load($query, $from, $to, $batchSize);
                        }

                        public function append(array $events, AppendCondition ...$appendConditions): void
                        {
                            $this->inner->append($events, ...$appendConditions);
                        }
                    };

                    $activityRepository = new ProjectionActivity(new PostgresDirtyKeyTracker($connection, $projectionPrefix.'dirty_keys'));
                    $runner = new ProjectionRunner(
                        $barrierStore,
                        new PostgresProjectionSegmentRepository($connection, $projectionPrefix),
                        new PostgresProjectionTransactionManager($connection),
                        $activityRepository,
                    );
                    $coordinator = new ProjectionCoordinator(
                        new ProjectionCatalog(
                            new ProjectionRuntime(
                                new ConcurrencyCourseNamesProjection($connection, $tableName),
                                new UltraNormalizer(),
                            ),
                        ),
                        $runner,
                        $activityRepository,
                        new PostgresCoordinatorLease($connection, $projectionPrefix.'coordinator_lease'),
                        new InMemoryProjectionActivator(),
                        new class implements ProjectionScalingPolicyInterface {
                            public function additionalWorkersRequested(array $affectedBuckets): int
                            {
                                return 1;
                            }
                        },
                    );

                    try {
                        return $coordinator->run('worker-a', batchSize: 100, maxCycles: 8);
                    } finally {
                        $connection->close();
                    }
                },
            );

            // Wait until the leader holds a segment before spawning the follower, so it
            // cannot race ahead and claim both segments.
            $leader->awaitCheckpoint('segment_claimed');

            // Follower: process the remote segment already claimed by the leader
            // coordinator while the leader is paused on its local segment.
            $follower = $cluster->spawn(
                static function () use ($dsn, $eventStorePrefix, $projectionPrefix, $tableName, $testFile): ProjectionRunResult {
                    require_once $testFile;

                    $connection = PostgresTestSupport::connection($dsn);
                    $activityRepository = new ProjectionActivity(new PostgresDirtyKeyTracker($connection, $projectionPrefix.'dirty_keys'));
                    $runner = new ProjectionRunner(
                        PostgresTestSupport::storeForImplementation($connection, $eventStorePrefix, 'query-streams'),
                        new PostgresProjectionSegmentRepository($connection, $projectionPrefix),
                        new PostgresProjectionTransactionManager($connection),
                        $activityRepository,
                    );
                    $runtime = new ProjectionRuntime(
                        new ConcurrencyCourseNamesProjection($connection, $tableName),
                        new UltraNormalizer(),
                    );

                    try {
                        return $runner->runClaimedOnce(
                            $runtime,
                            'course_names',
                            128,
                            'worker-a-course_names-128',
                            100,
                        );
                    } finally {
                        $connection->close();
                    }
                },
            );

            $followerOutcome = $follower->join();

            $leader->release('segment_claimed');
            $leaderOutcome = $leader->join();

            self::assertTrue($leaderOutcome->isSuccess(), $leaderOutcome->exceptionMessage() ?? '');

            /** @var ProjectionCoordinatorResult $leaderResult */
            $leaderResult = $leaderOutcome->value();
            self::assertTrue($leaderResult->acquiredLease);
            self::assertSame(1, $leaderResult->processedEvents);
            self::assertGreaterThanOrEqual(1, $leaderResult->acknowledgedBuckets);
            self::assertSame(1, $leaderResult->requestedWorkerActivations);

            self::assertTrue($followerOutcome->isSuccess(), $followerOutcome->exceptionMessage() ?? '');

            /** @var ProjectionRunResult $followerResult */
            $followerResult = $followerOutcome->value();
            self::assertTrue($followerResult->claimedSegment);
            self::assertSame(1, $followerResult->processedEvents);
            self::assertGreaterThanOrEqual(1, $followerResult->acknowledgedBuckets);

            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT course_id, name FROM %s ORDER BY course_id ASC', $tableName),
            );
            self::assertSame([
                ['course_id' => $leftCourseId, 'name' => 'Math 101'],
                ['course_id' => $rightCourseId, 'name' => 'Physics 201'],
            ], $rows);
            self::assertSame([], $activity->pendingInSegment('course_names', 0, 255));
            self::assertFalse($leases->hasLiveCoordinator(ProjectionCoordinator::COORDINATOR_KEY));
        });
    }

    /**
     * @return array{string, string}
     */
    private static function courseIdsAcrossSplit(): array
    {
        $left = null;
        $right = null;

        for ($i = 0; $i < 1024; ++$i) {
            $courseId = 'projection-concurrency-course-'.$i;
            $bucket = BucketHasher::bucketFor($courseId);

            if (null === $left && $bucket < 128) {
                $left = $courseId;
            }

            if (null === $right && $bucket >= 128) {
                $right = $courseId;
            }

            if (null !== $left && null !== $right) {
                return [$left, $right];
            }
        }

        throw new \RuntimeException('Failed to find course ids that land on both sides of the initial split.');
    }
}
