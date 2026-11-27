<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Sequence;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\CallbackEventStore;
use Ultra\Projection\Tests\Support\OwnedProjectionRows;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;
use Ultra\UnitOfWork\UnitOfWork;

require_once __DIR__ . '/Support/ProjectionModes.php';

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class BatchAtomicityTest extends ProjectionTestCase
{
    public function test_reentrant_batch_cannot_flush_or_clear_an_active_participant(): void
    {
        $rows = new OwnedProjectionRows();
        $projection = new PausingProjection($rows);
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer(), unitOfWork: [$rows]);
        $outer = new \Fiber($runtime->initialize(...));
        $outer->start();

        try {
            $runtime->applyBatch([]);
            self::fail('Expected an overlapping projection batch to be rejected.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('already has an active batch', $e->getMessage());
        }
        self::assertSame(['outer'], $rows->pending);
        self::assertSame(0, $rows->flushes);
        $outer->resume();
        self::assertSame(['outer'], $rows->stored);
        self::assertFalse($rows->hasPendingChanges());
    }

    #[DataProvider('failedBatches')]
    public function test_failed_application_or_flush_leaves_model_checkpoint_and_activity_retryable(
        string $mode,
        string $stage,
    ): void {
        $fixture = $this->fixture('postgres');
        $projection = $fixture->durableProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $bucket = BucketHasher::bucketFor('projection-atomicity-course');
        $fixture->store->append([self::courseCreatedEvent('projection-atomicity-course', 'Math')]);
        $fixture->activity->bump('durable_projection', $bucket);
        $fixture->runner->ensureInitialized($runtime);
        $projection->failAt = $stage;

        try {
            $fixture->runner->{$mode}($runtime, 'durable_projection', 0, 'worker-1');
            static::fail('Expected batch processing to fail.');
        } catch (\RuntimeException $exception) {
            static::assertSame('Failed ' . $stage . '.', $exception->getMessage());
        }

        static::assertSame([['action' => 'initialize', 'course_id' => null, 'name' => null]], $projection->rows());
        $segment = $fixture->segments->listSegments('durable_projection')[0];
        static::assertSame(0, $segment->checkpoint->gapFloor->value);
        static::assertNull($segment->workerId);
        static::assertSame(1, $fixture->activity->pendingInSegment('durable_projection', 0, 255)[0]->counter);
        static::assertFalse($fixture->connection->isTransactionActive());

        $projection->failAt = null;
        $result = $fixture->runner->{$mode}($runtime, 'durable_projection', 0, 'worker-2');

        static::assertSame(1, $result->processedEvents);
        static::assertTrue($result->caughtUp);
        static::assertSame(
            [
                ['action' => 'initialize', 'course_id' => null, 'name' => null],
                ['action' => 'apply', 'course_id' => 'projection-atomicity-course', 'name' => 'Math'],
                ['action' => 'flush', 'course_id' => null, 'name' => null],
            ],
            $projection->rows(),
        );
        $segment = $fixture->segments->listSegments('durable_projection')[0];
        static::assertSame(1, $segment->checkpoint->gapFloor->value);
        static::assertNull($segment->workerId);
        static::assertCount(
            'runClaimedOnce' === $mode ? 0 : 1,
            $fixture->activity->pendingInSegment('durable_projection', 0, 255),
        );
    }

    #[TestWith([null], 'expired after flush')]
    #[TestWith(['worker-2'], 'reassigned after observation')]
    #[TestWith(['worker-1'], 'reassigned to the same worker ID')]
    public function test_a_lost_claim_keeps_the_model_checkpoint_and_activity_retryable(?string $successor): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00Z'));
        $fixture = $this->fixture('postgres', $clock);
        $projection = $fixture->durableProjection();
        $normalizer = new UltraNormalizer();
        $runtime = new ProjectionRuntime($projection, $normalizer);
        $projectionId = $runtime->projectionId();
        $bucket = BucketHasher::bucketFor('course-1');
        $fixture->runner->ensureInitialized($runtime);
        $fixture->store->append([self::courseCreatedEvent('course-1', 'Math')]);
        $fixture->activity->bump($projectionId, $bucket);

        $eventStore = new CallbackEventStore($fixture->store, static function () use (
            $fixture,
            $projectionId,
            $bucket,
            $successor,
        ): void {
            $fixture->activity->bump($projectionId, $bucket);
            if (null !== $successor) {
                $segment = $fixture->segments->listSegments($projectionId)[0];
                $fixture->segments->releaseClaimedSegment($segment, 'worker-1');
                static::assertNotNull($fixture->segments->claimSegment($projectionId, 0, $successor));
            }
        });
        $afterFlush = $this->createStub(UnitOfWork::class);
        $afterFlush
            ->method('flush')
            ->willReturnCallback(static function () use ($clock, $successor): void {
                if (null === $successor) {
                    $clock->sleep(Duration::seconds(61));
                }
            });
        $interruptedRuntime = new ProjectionRuntime($projection, $normalizer, unitOfWork: [$afterFlush]);
        $runner = new ProjectionRunner($eventStore, $fixture->segments, $fixture->transactions, $fixture->activity);

        $result = $runner->runClaimedOnce($interruptedRuntime, $projectionId, 0, 'worker-1');

        static::assertFalse($result->claimedSegment);
        static::assertSame(0, $result->processedEvents);
        static::assertSame(0, $result->acknowledgedBuckets);
        static::assertSame(['initialize'], array_column($projection->rows(), 'action'));
        $segment = $fixture->segments->listSegments($projectionId)[0];
        static::assertSame(Checkpoint::beginning()->serialize(), $segment->checkpoint->serialize());
        static::assertFalse($fixture->connection->isTransactionActive());
        $pending = $fixture->activity->pendingInSegment($projectionId, 0, 255);
        static::assertSame([2], array_column($pending, 'counter'));
        if (null !== $successor) {
            static::assertSame($successor, $segment->workerId);
        }

        $fixture->runner->recoverExpiredClaims($projectionId);
        $retry = $fixture->runner->runClaimedOnce($runtime, $projectionId, 0, $successor ?? 'worker-2');

        static::assertTrue($retry->claimedSegment);
        static::assertSame(1, $retry->processedEvents);
        static::assertSame(1, $retry->acknowledgedBuckets);
        static::assertSame(['initialize', 'apply', 'flush'], array_column($projection->rows(), 'action'));
        static::assertTrue($fixture->segments->listSegments($projectionId)[0]->checkpoint->contains(new Sequence(1)));
        static::assertSame([], $fixture->activity->pendingInSegment($projectionId, 0, 255));
    }

    #[DataProvider('bufferedBatches')]
    public function test_projection_owned_unit_of_work_flushes_atomically_and_clears_after_failure(
        string $mode,
        string $stage,
    ): void {
        $fixture = $this->fixture('postgres');
        $projection = $fixture->bufferedProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $fixture->runner->ensureInitialized($runtime);
        $projection->failAt = $stage;
        $id = 'buffered-course';
        $fixture->store->append([self::courseCreatedEvent($id, 'Math')]);
        $fixture->activity->bump('buffered_projection', BucketHasher::bucketFor($id));

        $runner = $fixture->runner;
        try {
            $runner->{$mode}($runtime, 'buffered_projection', 0, 'worker');
            static::fail('Expected batch failure.');
        } catch (\RuntimeException|\Doctrine\DBAL\Exception) {
            static::assertFalse($projection->hasPendingChanges());
            static::assertSame(0, $runner->listSegments('buffered_projection')[0]->checkpoint->gapFloor->value);
            foreach ([$projection->coursesTable, $projection->indexTable] as $table) {
                static::assertSame(
                    [['id' => 'init', 'name' => 'initialized']],
                    $fixture->connection->fetchAllAssociative('SELECT * FROM ' . $table),
                );
            }
        }
        $projection->failAt = null;

        $result = $runner->{$mode}($runtime, 'buffered_projection', 0, 'worker');
        static::assertSame(1, $result->processedEvents);
        static::assertFalse($projection->hasPendingChanges());
        static::assertSame(
            [['id' => $id, 'name' => 'Math'], ['id' => 'init', 'name' => 'initialized']],
            $fixture->connection->fetchAllAssociative('SELECT * FROM ' . $projection->coursesTable . ' ORDER BY id'),
        );
        static::assertSame(
            [['id' => $id, 'name' => 'Math'], ['id' => 'init', 'name' => 'initialized']],
            $fixture->connection->fetchAllAssociative('SELECT * FROM ' . $projection->indexTable . ' ORDER BY id'),
        );
    }

    public static function bufferedBatches(): iterable
    {
        foreach (['runClaimedOnce', 'backfillClaimedOnce'] as $mode) {
            foreach (['apply', 'buffer'] as $stage) {
                yield $mode . ' ' . $stage => [$mode, $stage];
            }
        }
    }

    #[TestWith(['backfillClaimedOnce'], 'backfill')]
    #[TestWith(['runClaimedOnce'], 'with activity acknowledgement')]
    public function test_an_outer_transaction_owns_read_model_checkpoint_and_activity_visibility(string $mode): void
    {
        $fixture = $this->fixture('postgres');
        $projection = $fixture->durableProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $projectionId = $runtime->projectionId();
        $courseId = 'projection-outer-transaction-course';
        $fixture->store->append([self::courseCreatedEvent($courseId, 'Math')]);
        $fixture->activity->bump($projectionId, BucketHasher::bucketFor($courseId));
        $fixture->runner->ensureInitialized($runtime);
        $observer = PostgresConnectionSupport::connection(getenv('ULTRA_TEST_POSTGRES_DSN'));
        $observerTracker = new PostgresDirtyKeyTracker($observer, $fixture->prefix . 'dirty_keys');
        $fixture->connection->beginTransaction();
        try {
            $result = $fixture->runner->{$mode}($runtime, $projectionId, 0, 'worker');

            static::assertSame(1, $result->processedEvents);
            static::assertSame('runClaimedOnce' === $mode ? 1 : 0, $result->acknowledgedBuckets);
            static::assertSame(1, $fixture->connection->getTransactionNestingLevel());
            static::assertSame(3, count($projection->rows()));
            static::assertSame(1, (int) $observer->fetchOne('SELECT count(*) FROM ' . $projection->table));
            static::assertSame(
                0,
                Checkpoint::deserialize($observer->fetchOne(
                    'SELECT checkpoint FROM ' . $fixture->prefix . 'segments',
                ))->gapFloor->value,
            );
            static::assertCount(
                1 - $result->acknowledgedBuckets,
                $fixture->activity->pendingInSegment($projectionId, 0, 255),
            );
            static::assertSame([1], array_column($observerTracker->pendingForChannel($projectionId), 'counter'));

            $fixture->connection->rollBack();
            static::assertCount(1, $projection->rows());
            static::assertSame(
                0,
                $fixture->segments->listSegments('durable_projection')[0]->checkpoint->gapFloor->value,
            );
            static::assertSame(
                [1],
                array_column($fixture->activity->pendingInSegment($projectionId, 0, 255), 'counter'),
            );
        } finally {
            $observer->close();
        }
    }

    public static function failedBatches(): iterable
    {
        foreach (['runClaimedOnce', 'backfillClaimedOnce'] as $mode) {
            foreach (['apply', 'flush'] as $stage) {
                yield $mode . ' ' . $stage => [$mode, $stage];
            }
        }
    }
}

#[Projection(name: 'pausing', partitionBy: 'courseId')]
final readonly class PausingProjection
{
    public function __construct(
        private OwnedProjectionRows $rows,
    ) {}

    #[ProjectionInitializer]
    public function initialize(): void
    {
        $this->rows->pending[] = 'outer';
        \Fiber::suspend();
    }
}
