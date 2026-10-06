<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Checkpoint;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ProjectionTestCase;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class BatchAtomicityTest extends ProjectionTestCase
{
    #[DataProvider('failedBatches')]
    public function test_failed_application_or_flush_leaves_model_checkpoint_and_activity_retryable(string $mode, string $stage): void
    {
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
            self::fail('Expected batch processing to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Failed '.$stage.'.', $exception->getMessage());
        }

        self::assertSame([['action' => 'initialize', 'course_id' => null, 'name' => null]], $projection->rows());
        $segment = $fixture->segments->listSegments('durable_projection')[0];
        self::assertSame(0, $segment->checkpoint->gapFloor->value);
        self::assertNull($segment->workerId);
        self::assertSame(1, $fixture->activity->pendingInSegment('durable_projection', 0, 255)[0]->counter);
        self::assertFalse($fixture->connection->isTransactionActive());

        $projection->failAt = null;
        $result = $fixture->runner->{$mode}($runtime, 'durable_projection', 0, 'worker-2');

        self::assertSame(1, $result->processedEvents);
        self::assertTrue($result->caughtUp);
        self::assertSame([
            ['action' => 'initialize', 'course_id' => null, 'name' => null],
            ['action' => 'apply', 'course_id' => 'projection-atomicity-course', 'name' => 'Math'],
            ['action' => 'flush', 'course_id' => null, 'name' => null],
        ], $projection->rows());
        $segment = $fixture->segments->listSegments('durable_projection')[0];
        self::assertSame(1, $segment->checkpoint->gapFloor->value);
        self::assertNull($segment->workerId);
        self::assertCount('runClaimedOnce' === $mode ? 0 : 1, $fixture->activity->pendingInSegment('durable_projection', 0, 255));
    }

    public function test_an_outer_transaction_owns_read_model_and_checkpoint_visibility(): void
    {
        $fixture = $this->fixture('postgres');
        $projection = $fixture->durableProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $fixture->store->append([self::courseCreatedEvent('projection-outer-transaction-course', 'Math')]);
        $fixture->runner->ensureInitialized($runtime);
        $observer = PostgresConnectionSupport::connection(getenv('ULTRA_TEST_POSTGRES_DSN'));
        $fixture->connection->beginTransaction();
        try {
            $result = $fixture->runner->backfillClaimedOnce($runtime, 'durable_projection', 0, 'worker');

            self::assertSame(1, $result->processedEvents);
            self::assertSame(1, $fixture->connection->getTransactionNestingLevel());
            self::assertSame(3, count($projection->rows()));
            self::assertSame(1, (int) $observer->fetchOne('SELECT count(*) FROM '.$projection->table));
            self::assertSame(0, Checkpoint::deserialize($observer->fetchOne('SELECT checkpoint FROM '.$fixture->prefix.'segments'))->gapFloor->value);

            $fixture->connection->rollBack();
            self::assertCount(1, $projection->rows());
            self::assertSame(0, $fixture->segments->listSegments('durable_projection')[0]->checkpoint->gapFloor->value);
        } finally {
            $observer->close();
        }
    }

    public static function failedBatches(): iterable
    {
        foreach (['runClaimedOnce', 'backfillClaimedOnce'] as $mode) {
            foreach (['apply', 'flush'] as $stage) {
                yield $mode.' '.$stage => [$mode, $stage];
            }
        }
    }
}
