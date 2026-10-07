<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionRuntime;
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

        $fixture->runner->ensureInitialized($runtime);
        $fixture->runner->ensureInitialized($runtime);
        static::assertSame(1, $projection->initialized);
        static::assertCount(1, $fixture->segments->listSegments('tracking_projection'));
        $fixture->runner->splitSegment('tracking_projection', 0);
        $projection->names = ['course-1' => 'Math'];

        $fixture->runner->deleteProjection($runtime);

        static::assertSame(1, $projection->resets);
        static::assertSame([], $projection->names);
        static::assertSame([], $fixture->segments->listSegments('tracking_projection'));
        $fixture->runner->ensureInitialized($runtime);
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
            $fixture->runner->splitSegment('durable_projection', 0);
        }
        $projection->failAt = $stage;

        try {
            if ('initialize' === $stage) {
                $fixture->runner->ensureInitialized($runtime);
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
            $fixture->runner->ensureInitialized($runtime);
            static::assertCount(1, $fixture->segments->listSegments('durable_projection'));
        } else {
            $fixture->runner->deleteProjection($runtime);
            static::assertSame([], $fixture->segments->listSegments('durable_projection'));
        }
    }

    public static function lifecycleFailures(): iterable
    {
        yield 'initialize' => ['initialize'];
        yield 'reset' => ['reset'];
    }
}
