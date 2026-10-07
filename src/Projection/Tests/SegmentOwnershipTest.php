<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Ultra\Mapper\UltraNormalizer;
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
    public function test_competing_claims_wait_for_the_callers_transaction_outcome(bool $commit): void
    {
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

            $worker = $cluster->spawn(static function () use ($dsn, $prefix): ?string {
                $connection = PostgresConnectionSupport::connection($dsn);
                try {
                    $segments = new PostgresProjectionSegmentRepository($connection, $prefix);
                    checkpoint('ready', [
                        'pid' => (int) $connection->fetchOne('SELECT pg_backend_pid()'),
                        'owner' => $segments->listSegments('courses')[0]->workerId,
                    ]);

                    return $segments->claimSegment('courses', 0, 'worker-2')?->workerId;
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

    public static function claimTransactions(): iterable
    {
        yield 'commit preserves ownership' => [true];
        yield 'rollback makes the claim available' => [false];
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
    public function test_a_foreign_worker_cannot_execute_an_existing_claim(string $backend): void
    {
        $runtime = new ProjectionRuntime(new TrackingProjection(), new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $segments = $fixture->segments;
        $activity = $fixture->activity;
        $runner = $fixture->runner;

        $runner->ensureInitialized($runtime);
        static::assertNotNull($runner->claimSegment('tracking_projection', 0, 'worker-1'));

        $result = $runner->runClaimedOnce($runtime, 'tracking_projection', 0, 'worker-2');

        static::assertFalse($result->claimedSegment);
        static::assertSame('worker-1', $segments->listSegments('tracking_projection')[0]->workerId);
    }
}
