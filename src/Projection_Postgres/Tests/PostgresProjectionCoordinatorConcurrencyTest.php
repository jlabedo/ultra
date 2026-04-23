<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionCoordinatorResult;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivityRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Testing\Concurrent\Cluster;

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
            ProjectionBucketActivityRepositoryInterface $activity,
            PostgresProjectionCoordinatorLeaseRepository $leases,
            string $eventStorePrefix,
            string $projectionPrefix,
        ) use ($dsn): void {
            $tableName = $projectionPrefix . 'course_names_view';
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
            $segments->reconcilePendingOperations('course_names');
            $activity->bump('course_names', $leftBucket);
            $activity->bump('course_names', $rightBucket);

            $supportFile = __DIR__ . '/../../EventStore_Postgres/Tests/Support/PostgresTestSupport.php';
            $testFile = __FILE__;

            $cluster = new Cluster(timeout: 10.0);

            // Leader: claims its segment, then pauses at 'segment_claimed' before loading
            // events so the follower can concurrently claim the other segment.
            $leader = $cluster->spawn(
                static function () use ($dsn, $eventStorePrefix, $projectionPrefix, $tableName, $supportFile, $testFile): ProjectionCoordinatorResult {
                    require_once $supportFile;
                    require_once $testFile;

                    $connection = \Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection($dsn);
                    $innerStore = \Ultra\EventStore_Postgres\Tests\Support\postgresTestStoreForImplementation($connection, $eventStorePrefix, 'query-streams');

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

                    $runner = new ProjectionRunner(
                        $barrierStore,
                        new \Ultra\Projection_Postgres\PostgresProjectionSegmentRepository($connection, $projectionPrefix),
                    );
                    $coordinator = new ProjectionCoordinator(
                        new ProjectionCatalog(
                            new ProjectionRuntime(
                                new ConcurrencyCourseNamesProjection($connection, $tableName),
                                new UltraNormalizer(),
                            ),
                        ),
                        $runner,
                        new PostgresProjectionBucketActivityRepository($connection, $projectionPrefix),
                        new PostgresProjectionCoordinatorLeaseRepository($connection, $projectionPrefix),
                        new InMemoryProjectionActivator(),
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

            // Follower: no barrier, runs to completion while the leader is paused.
            $follower = $cluster->spawn(
                static function () use ($dsn, $eventStorePrefix, $projectionPrefix, $tableName, $supportFile, $testFile): ProjectionCoordinatorResult {
                    require_once $supportFile;
                    require_once $testFile;

                    $connection = \Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection($dsn);
                    $runner = new ProjectionRunner(
                        \Ultra\EventStore_Postgres\Tests\Support\postgresTestStoreForImplementation($connection, $eventStorePrefix, 'query-streams'),
                        new PostgresProjectionSegmentRepository($connection, $projectionPrefix),
                    );
                    $coordinator = new ProjectionCoordinator(
                        new ProjectionCatalog(
                            new ProjectionRuntime(
                                new ConcurrencyCourseNamesProjection($connection, $tableName),
                                new UltraNormalizer(),
                            ),
                        ),
                        $runner,
                        new PostgresProjectionBucketActivityRepository($connection, $projectionPrefix),
                        new PostgresProjectionCoordinatorLeaseRepository($connection, $projectionPrefix),
                        new InMemoryProjectionActivator(),
                    );

                    try {
                        return $coordinator->run('worker-b', batchSize: 100, maxCycles: 8);
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

            self::assertTrue($followerOutcome->isSuccess(), $followerOutcome->exceptionMessage() ?? '');
            /** @var ProjectionCoordinatorResult $followerResult */
            $followerResult = $followerOutcome->value();
            self::assertFalse($followerResult->acquiredLease);
            self::assertSame(1, $followerResult->processedEvents);
            self::assertGreaterThanOrEqual(1, $followerResult->acknowledgedBuckets);

            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT course_id, name FROM %s ORDER BY course_id ASC', $tableName),
            );
            self::assertSame([
                ['course_id' => $leftCourseId, 'name' => 'Math 101'],
                ['course_id' => $rightCourseId, 'name' => 'Physics 201'],
            ], $rows);
            self::assertSame([], $activity->pendingBuckets('course_names'));
            self::assertFalse($leases->hasLiveCoordinator());
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
            $courseId = 'course-' . $i;
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
