<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Sequence;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionRunResult;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\TrackingProjection;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class SegmentOwnershipTest extends ProjectionTestCase
{
    #[DataProvider('backends')]
    public function test_only_first_creation_initializes_the_full_bucket_range(string $backend): void
    {
        $repository = $this->fixture($backend)->segments;

        static::assertTrue($repository->ensureInitialized('courses'));
        static::assertFalse($repository->ensureInitialized('courses'));
        $segments = $repository->listSegments('courses');
        static::assertCount(1, $segments);
        static::assertSame(0, $segments[0]->bucketFrom);
        static::assertSame(255, $segments[0]->bucketTo);
        static::assertNull($segments[0]->workerId);
    }

    #[DataProvider('backends')]
    public function test_competing_workers_cannot_claim_an_owned_segment(string $backend): void
    {
        $repository = $this->fixture($backend)->segments;
        $repository->ensureInitialized('courses');

        $claimed = $repository->claimSegment('courses', 0, 'worker-1');
        static::assertNotNull($claimed);
        static::assertSame('courses', $claimed->projectionId);
        static::assertSame(0, $claimed->bucketFrom);
        static::assertSame(255, $claimed->bucketTo);
        static::assertSame(0, $claimed->checkpoint->gapFloor->value);
        static::assertSame('worker-1', $claimed->workerId);
        static::assertNull($repository->claimSegment('courses', 0, 'worker-2'));
    }

    #[DataProvider('claimTransactions')]
    #[Group('slow')]
    #[Group('projection-concurrency')]
    public function test_competing_claims_wait_for_the_callers_transaction_outcome(
        bool $commit,
        bool $claimFullRange,
    ): void {
        $fixture = $this->fixture('postgres');
        $fixture->segments->ensureInitialized('courses');
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        $prefix = $fixture->prefix;
        $holderPid = (int) $fixture->connection->fetchOne('SELECT pg_backend_pid()');
        $cluster = new Cluster(timeout: 5.0);
        $worker = null;

        try {
            $fixture->connection->beginTransaction();
            static::assertNotNull($fixture->segments->claimSegment('courses', 0, 'worker-1'));
            static::assertSame(1, $fixture->connection->getTransactionNestingLevel());
            static::assertSame('worker-1', $fixture->segments->listSegments('courses')[0]->workerId);

            $worker = $cluster->spawn(static function () use ($dsn, $prefix, $claimFullRange): ?string {
                $connection = PostgresConnectionSupport::connection($dsn);
                try {
                    $segments = new PostgresProjectionSegmentRepository($connection, $prefix);
                    checkpoint('ready', [
                        'pid' => (int) $connection->fetchOne('SELECT pg_backend_pid()'),
                        'owner' => $segments->listSegments('courses')[0]->workerId,
                    ]);

                    $claim = $claimFullRange
                        ? $segments->claimFullRangeSegment('courses', 'worker-2')
                        : $segments->claimSegment('courses', 0, 'worker-2');

                    return $claim?->workerId;
                } finally {
                    $connection->close();
                }
            });
            $ready = $worker->awaitCheckpoint('ready');
            static::assertNull($ready->payload['owner']);
            $worker->release('ready');

            // Observe the claim waiting on our lock before letting the same request resume.
            $deadline = microtime(true) + 5.0;
            $blocked = 0;
            do {
                // Pump the worker protocol so the release reaches the child process.
                $outcome = $worker->outcome();
                if (null !== $outcome) {
                    break;
                }
                $blocked = (int) $fixture->connection->fetchOne('SELECT count(*) FROM unnest(pg_blocking_pids(CAST(? AS INTEGER))) AS blocker(pid)
                     WHERE pid = CAST(? AS INTEGER)', [
                    $ready->payload['pid'],
                    $holderPid,
                ]);
                if (1 === $blocked) {
                    break;
                }
                usleep(1_000);
            } while (microtime(true) < $deadline);
            static::assertSame(
                1,
                $blocked,
                'The competing claim must wait on the caller-held lock. ' . $outcome?->exceptionMessage(),
            );

            if ($commit) {
                $fixture->connection->commit();
            } else {
                $fixture->connection->rollBack();
            }

            $outcome = $worker->join();
            static::assertTrue($outcome->isSuccess(), $outcome->exceptionMessage());
            static::assertSame($commit ? null : 'worker-2', $outcome->value());
            static::assertSame(
                $commit ? 'worker-1' : 'worker-2',
                $fixture->segments->listSegments('courses')[0]->workerId,
            );
        } finally {
            $worker?->stop();
            PostgresConnectionSupport::resetSharedConnection($fixture->connection);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function claimTransactions(): iterable
    {
        yield 'commit preserves ownership' => [true, false];
        yield 'rollback makes the claim available' => [false, false];
        yield 'commit prevents a full-range claim' => [true, true];
        yield 'rollback allows a full-range claim' => [false, true];
    }

    #[DataProvider('backends')]
    public function test_expired_segment_claims_become_available_to_another_worker(string $backend): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = $this->fixture($backend, $clock)->segments;
        $repository->ensureInitialized('courses');

        static::assertNotNull($repository->claimSegment('courses', 0, 'worker-1', Duration::seconds(30)));

        $clock->sleep(Duration::seconds(31));

        static::assertSame(1, $repository->recoverExpiredClaims('courses'));
        static::assertNotNull($repository->claimSegment('courses', 0, 'worker-2', Duration::seconds(30)));
    }

    #[DataProvider('backends')]
    public function test_only_a_free_or_expired_full_range_segment_can_be_claimed(string $backend): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = $this->fixture($backend, $clock)->segments;
        static::assertNull($repository->claimFullRangeSegment('courses', 'worker-1'));
        static::assertTrue($repository->ensureInitialized('courses'));
        $seed = $repository->claimSegment('courses', 0, 'seed');
        static::assertNotNull($seed);
        $checkpoint = Checkpoint::at(new Sequence(42));
        $repository->completeClaimedSegment($seed, $checkpoint, 'seed', caughtUp: true);

        $claimed = $repository->claimFullRangeSegment('courses', 'worker-1', Duration::seconds(30));
        static::assertNotNull($claimed);
        static::assertSame([0, 255, 'worker-1'], [$claimed->bucketFrom, $claimed->bucketTo, $claimed->workerId]);
        $clock->sleep(Duration::seconds(30));
        static::assertNull($repository->claimFullRangeSegment('courses', 'worker-1'));
        static::assertNull($repository->claimFullRangeSegment('courses', 'worker-2'));
        $clock->sleep(Duration::seconds(1));

        $reclaimed = $repository->claimFullRangeSegment('courses', 'worker-2');
        static::assertNotNull($reclaimed);
        static::assertSame('worker-2', $reclaimed->workerId);
        static::assertSame($checkpoint->serialize(), $reclaimed->checkpoint->serialize());
        $repository->releaseClaimedSegment($reclaimed, 'worker-2');
        $repository->splitSegment('courses', 0);
        static::assertNull($repository->claimFullRangeSegment('courses', 'worker-3'));
    }

    #[DataProvider('backends')]
    public function test_a_foreign_worker_cannot_execute_an_existing_claim(string $backend): void
    {
        $runtime = new ProjectionRuntime(new TrackingProjection(), new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $segments = $fixture->segments;
        $runner = $fixture->runner;

        $runner->ensureInitialized($runtime);
        static::assertNotNull($runner->claimSegment($runtime, 0, 'worker-1'));

        $batches = $runner->runClaimedBatches($runtime, 'tracking_projection', 0, 'worker-2');
        $result = $batches->current();

        static::assertInstanceOf(ProjectionRunResult::class, $result);
        static::assertFalse($result->claimedSegment);
        static::assertSame(0, $result->processedEvents);
        static::assertSame('worker-1', $segments->listSegments('tracking_projection')[0]->workerId);

        $batches->next();
        static::assertFalse($batches->valid());
    }

    #[DataProvider('backends')]
    public function test_a_batch_sequence_stops_after_its_segment_claim_is_released(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $projectionId = $runtime->projectionId();
        $courseId = 'released-claim-course';
        $fixture->store->append([
            self::courseCreatedEvent($courseId, 'First'),
            self::courseCreatedEvent($courseId, 'Second'),
        ]);
        $fixture->activity->bump($projectionId, BucketHasher::bucketFor($courseId));

        $batches = $fixture->runner->runClaimedBatches($runtime, $projectionId, 0, 'worker-1', limit: 1);
        $first = $batches->current();
        static::assertInstanceOf(ProjectionRunResult::class, $first);
        static::assertTrue($first->claimedSegment);
        static::assertFalse($first->caughtUp);
        $segment = $fixture->segments->listSegments($projectionId)[0];
        $fixture->segments->releaseClaimedSegment($segment, 'worker-1');

        $batches->next();
        $last = $batches->current();

        static::assertInstanceOf(ProjectionRunResult::class, $last);
        static::assertFalse($last->claimedSegment);
        static::assertSame(0, $last->acknowledgedBuckets);
        static::assertSame(['First'], $projection->applied);
        static::assertNull($fixture->segments->listSegments($projectionId)[0]->workerId);
        $pending = $fixture->activity->pendingInSegment($projectionId, 0, 255);
        static::assertCount(1, $pending);
        static::assertSame(1, $pending[0]->counter);

        $batches->next();
        static::assertFalse($batches->valid());
    }
}
