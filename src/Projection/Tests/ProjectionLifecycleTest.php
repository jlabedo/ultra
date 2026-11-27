<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionTransactionManagerInterface;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\TrackingProjection;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class ProjectionLifecycleTest extends ProjectionTestCase
{
    #[DataProvider('backends')]
    public function test_initialization_is_once_per_creation_and_reset_deletes_all_segment_state(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

        $first = $fixture->runner->runFullRangeBatches($runtime, 'worker-1')->current();
        static::assertTrue($first->claimedSegment);
        static::assertSame(1, $projection->initialized);
        static::assertCount(1, $fixture->segments->listSegments('tracking_projection'));

        $transactions = $this->createMock(ProjectionTransactionManagerInterface::class);
        $transactions
            ->expects(static::once())
            ->method('transactional')
            ->willReturnCallback($fixture->transactions->transactional(...));
        $runner = new ProjectionRunner($fixture->store, $fixture->segments, $transactions, $fixture->activity);
        $next = $runner->runFullRangeBatches($runtime, 'worker-2')->current();
        static::assertTrue($next->claimedSegment);
        static::assertSame(1, $projection->initialized);

        $fixture->runner->splitSegment($runtime, 0);
        $projection->names = ['course-1' => 'Math'];

        $fixture->runner->deleteProjection($runtime);

        static::assertSame(1, $projection->resets);
        static::assertSame([], $projection->names);
        static::assertSame([], $fixture->segments->listSegments('tracking_projection'));
        $afterReset = $fixture->runner->runFullRangeBatches($runtime, 'worker-3')->current();
        static::assertTrue($afterReset->claimedSegment);
        static::assertSame(2, $projection->initialized);
        $segment = $fixture->segments->listSegments('tracking_projection')[0];
        static::assertSame([0, 255, 0, null], [
            $segment->bucketFrom,
            $segment->bucketTo,
            $segment->checkpoint->gapFloor->value,
            $segment->workerId,
        ]);
    }

    #[DataProvider('lifecycleFailures')]
    public function test_lifecycle_failure_rolls_back_read_model_and_segment_creation_or_deletion(string $stage): void
    {
        $fixture = $this->fixture('postgres');
        $projection = $fixture->durableProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        if ('reset' === $stage) {
            $fixture->runner->ensureInitialized($runtime);
            $fixture->runner->splitSegment($runtime, 0);
        }
        $projection->failAt = $stage;

        try {
            if ('initialize' === $stage) {
                $fixture->runner->runFullRangeBatches($runtime, 'worker-1')->current();
            } else {
                $fixture->runner->deleteProjection($runtime);
            }
            static::fail('Expected the lifecycle hook to fail.');
        } catch (\RuntimeException $exception) {
            static::assertSame('Failed ' . $stage . '.', $exception->getMessage());
        }

        if ('initialize' === $stage) {
            static::assertSame([], $fixture->segments->listSegments('durable_projection'));
            static::assertNull($fixture->connection->fetchOne('SELECT to_regclass(?)', [$projection->table]));
        } else {
            static::assertCount(2, $fixture->segments->listSegments('durable_projection'));
            static::assertSame([['action' => 'initialize', 'course_id' => null, 'name' => null]], $projection->rows());
        }
        static::assertFalse($fixture->connection->isTransactionActive());

        $projection->failAt = null;
        if ('initialize' === $stage) {
            $retry = $fixture->runner->runFullRangeBatches($runtime, 'worker-2')->current();
            static::assertTrue($retry->claimedSegment);
            static::assertCount(1, $fixture->segments->listSegments('durable_projection'));
            static::assertSame(['initialize', 'flush'], array_column($projection->rows(), 'action'));
        } else {
            $fixture->runner->deleteProjection($runtime);
            static::assertSame([], $fixture->segments->listSegments('durable_projection'));
        }
    }

    #[DataProvider('bufferedResetFailures')]
    public function test_failed_buffered_reset_clears_pending_deletions_and_preserves_persisted_state(string $stage): void
    {
        $fixture = $this->fixture('postgres');
        $projection = $fixture->bufferedProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $fixture->runner->ensureInitialized($runtime);
        $fixture->runner->splitSegment($runtime, 0);
        $projection->failAt = $stage;
        try {
            $fixture->runner->deleteProjection($runtime);
            static::fail('Expected reset failure.');
        } catch (\RuntimeException $exception) {
            static::assertSame('Failed ' . $stage . '.', $exception->getMessage());
            static::assertFalse($projection->hasPendingChanges());
        }
        static::assertCount(2, $fixture->runner->listSegments('buffered_projection'));
        foreach ([$projection->coursesTable, $projection->indexTable] as $table) {
            static::assertSame(1, (int) $fixture->connection->fetchOne('SELECT COUNT(*) FROM ' . $table));
        }

        $projection->failAt = null;
        $fixture->runner->deleteProjection($runtime);
        static::assertSame([], $fixture->runner->listSegments('buffered_projection'));
        foreach ([$projection->coursesTable, $projection->indexTable] as $table) {
            static::assertSame(0, (int) $fixture->connection->fetchOne('SELECT COUNT(*) FROM ' . $table));
        }
        static::assertFalse($projection->hasPendingChanges());
    }

    /** @return iterable<string, array{string}> */
    public static function bufferedResetFailures(): iterable
    {
        yield 'during reset preparation' => ['reset'];
        yield 'after the mapped-table flush' => ['reset flush'];
    }

    public static function lifecycleFailures(): iterable
    {
        yield 'initialize' => ['initialize'];
        yield 'reset' => ['reset'];
    }
}
