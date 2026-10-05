<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionCoordinatorResult;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRunResult;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\PostgresProjectionTransactionManager;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

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

/**
 * @internal
 */
#[CoversNothing]
#[Group('slow')]
#[Group('projection-concurrency')]
final class PostgresProjectionCoordinatorConcurrencyTest extends PostgresProjectionTestCase
{
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
     * Two workers race to acquire the coordinator lease at the same frozen time — only
     * the first succeeds.  A third worker, given a clock that is one second past the
     * first worker's lease expiry, can steal the lease even though it was never
     * explicitly released.
     *
     * Clock values are injected into each worker via checkpoint release so the parent
     * process controls the perceived wall-clock without any real time passing.
     */
    public function test_stale_leader_lease_can_be_overtaken_after_expiry(): void
    {
        $dsn = $this->postgresDsn();

        $this->withPostgresProjectionCoordination(function (
            Connection $connection,
            PostgresProjectionSegmentRepository $segments,
            ProjectionActivity $activity,
            CoordinatorLease $leases,
            string $projectionPrefix,
        ) use ($dsn): void {
            $cluster = new Cluster(timeout: 10.0);

            // Worker A — stalled leader: acquires the lease at T=0 then pauses so the
            // parent can verify the lease is visible before spawning the contenders.
            // The lease is intentionally never released, simulating a coordinator crash.
            $stalledLeader = $cluster->spawn(
                static function () use ($dsn, $projectionPrefix): bool {
                    $connection = PostgresTestSupport::connection($dsn);
                    $clock = new StatickClock(new \DateTimeImmutable('2000-01-01 00:00:00'));
                    $leases = new PostgresCoordinatorLease($connection, $projectionPrefix.'coordinator_lease',
                        $clock,
                    );

                    try {
                        $acquired = $leases->acquire(ProjectionCoordinator::COORDINATOR_KEY, 'stalled-leader', new Duration(seconds: 60));
                        checkpoint('lease_acquired');

                        return $acquired;
                    } finally {
                        $connection->close();
                    }
                },
            );

            // Guarantee Worker A holds the lease before spawning contenders.
            $stalledLeader->awaitCheckpoint('lease_acquired');

            // Worker B — early contender: sees the same T=0, must fail to acquire.
            $earlyContender = $cluster->spawn(
                static function () use ($dsn, $projectionPrefix): bool {
                    $connection = PostgresTestSupport::connection($dsn);
                    $clock = new StatickClock(new \DateTimeImmutable('2000-01-01 00:00:00'));
                    $leases = new PostgresCoordinatorLease($connection, $projectionPrefix.'coordinator_lease',
                        $clock,
                    );

                    try {
                        return $leases->acquire(ProjectionCoordinator::COORDINATOR_KEY, 'early-contender', new Duration(seconds: 60));
                    } finally {
                        $connection->close();
                    }
                },
            );

            // Worker C — late contender: waits for the parent to inject a timestamp that
            // is past the stalled leader's lease expiry, then tries to acquire.
            $lateContender = $cluster->spawn(
                static function () use ($dsn, $projectionPrefix): bool {
                    $connection = PostgresTestSupport::connection($dsn);

                    // Block until the parent tells us what "now" is.  The injected
                    // timestamp will be one second past the stalled leader's expiry.
                    $injectedTime = checkpoint('need_time');

                    $clock = new StatickClock(new \DateTimeImmutable($injectedTime));
                    $leases = new PostgresCoordinatorLease($connection, $projectionPrefix.'coordinator_lease',
                        $clock,
                    );

                    try {
                        return $leases->acquire(ProjectionCoordinator::COORDINATOR_KEY, 'late-contender', new Duration(seconds: 60));
                    } finally {
                        $connection->close();
                    }
                },
            );

            $earlyContenderOutcome = $earlyContender->join();

            // Await Worker C's checkpoint before releasing A so the lease is definitely
            // still in the DB (un-released) when C makes its acquisition attempt.
            $lateContender->awaitCheckpoint('need_time');

            $stalledLeader->release('lease_acquired');
            $stalledLeaderOutcome = $stalledLeader->join();

            // Lease duration is 60 s from T=0 → expires at 00:01:00.  Inject T+61 s.
            $lateContender->release('need_time', '2000-01-01 00:01:01');
            $lateContenderOutcome = $lateContender->join();

            self::assertTrue($stalledLeaderOutcome->isSuccess(), $stalledLeaderOutcome->exceptionMessage() ?? '');
            self::assertTrue($stalledLeaderOutcome->value(), 'stalled leader must have acquired the lease');

            self::assertTrue($earlyContenderOutcome->isSuccess(), $earlyContenderOutcome->exceptionMessage() ?? '');
            self::assertFalse($earlyContenderOutcome->value(), 'early contender must be blocked by the live lease');

            self::assertTrue($lateContenderOutcome->isSuccess(), $lateContenderOutcome->exceptionMessage() ?? '');
            self::assertTrue($lateContenderOutcome->value(), 'late contender must steal the expired lease');
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
            $courseId = 'course-'.$i;
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
