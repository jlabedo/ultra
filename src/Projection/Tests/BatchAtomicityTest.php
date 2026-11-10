<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Checkpoint;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionTransactionManagerInterface;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Testing\PostgresConnectionSupport;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class BatchAtomicityTest extends ProjectionTestCase
{
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

    #[DataProvider('bufferedBatches')]
    public function test_projection_owned_unit_of_work_flushes_atomically_and_clears_after_failure(
        string $mode,
        ?string $stage,
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
        if ('transaction' === $stage) {
            $transactions = new class($fixture->transactions) implements ProjectionTransactionManagerInterface {
                private int $calls = 0;

                public function __construct(private ProjectionTransactionManagerInterface $inner) {}

                public function transactional(callable $operation): mixed
                {
                    ++$this->calls;
                    return $this->inner->transactional(function () use ($operation): mixed {
                        $result = $operation();
                        // The first transaction only checks initialization; the second commits the batch.
                        if (2 === $this->calls) {
                            throw new \RuntimeException('Failed transaction.');
                        }
                        return $result;
                    });
                }
            };
            $runner = new ProjectionRunner($fixture->store, $fixture->segments, $transactions, $fixture->activity);
        }

        if (null !== $stage) {
            try {
                $runner->{$mode}($runtime, 'buffered_projection', 0, 'worker');
                static::fail('Expected batch failure.');
            } catch (\RuntimeException | \Doctrine\DBAL\Exception) {
                static::assertFalse($projection->hasPendingChanges());
                static::assertSame(0, $runner->listSegments('buffered_projection')[0]->checkpoint->gapFloor->value);
                foreach ([$projection->coursesTable, $projection->indexTable] as $table) {
                    static::assertSame([['id' => 'init', 'name' => 'initialized']], $fixture->connection->fetchAllAssociative(
                        'SELECT * FROM ' . $table,
                    ));
                }
            }
            $projection->failAt = null;
            $runner = $fixture->runner;
        }

        $result = $runner->{$mode}($runtime, 'buffered_projection', 0, 'worker');
        static::assertSame(1, $result->processedEvents);
        static::assertFalse($projection->hasPendingChanges());
        static::assertSame(
            [['id' => $id, 'name' => 'Math'], ['id' => 'init', 'name' => 'flushed']],
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
            foreach ([null, 'apply', 'hook', 'buffer', 'transaction'] as $stage) {
                yield $mode . ' ' . ($stage ?? 'success') => [$mode, $stage];
            }
        }
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

            static::assertSame(1, $result->processedEvents);
            static::assertSame(1, $fixture->connection->getTransactionNestingLevel());
            static::assertSame(3, count($projection->rows()));
            static::assertSame(1, (int) $observer->fetchOne('SELECT count(*) FROM ' . $projection->table));
            static::assertSame(
                0,
                Checkpoint::deserialize($observer->fetchOne(
                    'SELECT checkpoint FROM ' . $fixture->prefix . 'segments',
                ))->gapFloor->value,
            );

            $fixture->connection->rollBack();
            static::assertCount(1, $projection->rows());
            static::assertSame(
                0,
                $fixture->segments->listSegments('durable_projection')[0]->checkpoint->gapFloor->value,
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
