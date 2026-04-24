<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Sequence;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionSegment;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/**
 * @internal
 */
#[CoversClass(InMemoryProjectionSegmentRepository::class)]
#[CoversClass(ProjectionSegment::class)]
final class InMemoryProjectionSegmentRepositoryTest extends TestCase
{
    public function test_split_of_an_available_segment_is_reconciled_into_two_children(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');

        $repository->splitSegment('courses', 0);
        $repository->reconcilePendingOperations('courses');

        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 127, 'status' => 'available', 'pendingOperation' => null],
            ['bucketFrom' => 128, 'bucketTo' => 255, 'status' => 'available', 'pendingOperation' => null],
        ], $repository->listSegments('courses'));
    }

    public function test_ensure_initialized_returns_false_when_the_projection_already_exists(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();

        self::assertTrue($repository->ensureInitialized('courses'));
        self::assertFalse($repository->ensureInitialized('courses'));
        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 255, 'status' => 'available', 'pendingOperation' => null],
        ], $repository->listSegments('courses'));
    }

    public function test_split_of_an_active_assignment_is_deferred_until_the_worker_completes(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $assignment = $repository->assignSegment('courses', 0, 'worker-1');
        self::assertNotNull($assignment);
        $segment = $repository->claimAssignedSegment($assignment);
        self::assertNotNull($segment);

        $repository->splitSegment('courses', 0);

        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 255, 'status' => 'draining', 'pendingOperation' => 'split'],
        ], $repository->listSegments('courses'));

        $repository->completeAssignedBatch($segment, Checkpoint::at(new Sequence(5)), 'worker-1', true);
        $repository->reconcilePendingOperations('courses');

        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 127, 'status' => 'available', 'pendingOperation' => null],
            ['bucketFrom' => 128, 'bucketTo' => 255, 'status' => 'available', 'pendingOperation' => null],
        ], $repository->listSegments('courses'));
    }

    public function test_merge_waits_for_both_siblings_and_keeps_the_earliest_checkpoint(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $repository->splitSegment('courses', 0);
        $repository->reconcilePendingOperations('courses');
        $leftAssignment = $repository->assignSegment('courses', 0, 'worker-left');
        $rightAssignment = $repository->assignSegment('courses', 128, 'worker-right');
        self::assertNotNull($leftAssignment);
        self::assertNotNull($rightAssignment);
        $left = $repository->claimAssignedSegment($leftAssignment);
        $right = $repository->claimAssignedSegment($rightAssignment);
        self::assertNotNull($left);
        self::assertNotNull($right);

        $repository->requestMerge('courses', 0, 255);
        $repository->completeAssignedBatch($left, Checkpoint::at(new Sequence(10)), 'worker-left', true);

        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 127, 'status' => 'drained', 'pendingOperation' => 'merge'],
            ['bucketFrom' => 128, 'bucketTo' => 255, 'status' => 'draining', 'pendingOperation' => 'merge'],
        ], $repository->listSegments('courses'));

        $repository->completeAssignedBatch($right, Checkpoint::at(new Sequence(7)), 'worker-right', true);
        $repository->reconcilePendingOperations('courses');

        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 255, 'status' => 'available', 'pendingOperation' => null],
        ], $repository->listSegments('courses'));
    }

    public function test_assign_segment_returns_null_when_the_segment_is_not_available(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');

        self::assertNotNull($repository->assignSegment('courses', 0, 'worker-1'));
        self::assertNull($repository->assignSegment('courses', 0, 'worker-2'));
    }

    public function test_projection_segment_exposes_its_state(): void
    {
        $segment = new ProjectionSegment('courses', 128, 255, 'available', null, Checkpoint::beginning(), false);

        self::assertSame('courses', $segment->projectionId);
        self::assertSame(128, $segment->bucketFrom);
        self::assertSame(255, $segment->bucketTo);
        self::assertSame('available', $segment->status);
        self::assertNull($segment->pendingOperation);
        self::assertSame(0, $segment->checkpoint->gapFloor->value);
        self::assertFalse($segment->wasCreated);
    }

    public function test_expired_assignments_are_recovered_against_the_injected_clock(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = new InMemoryProjectionSegmentRepository($clock);
        $repository->ensureInitialized('courses');

        self::assertNotNull($repository->assignSegment('courses', 0, 'worker-1', Duration::seconds(30)));

        $clock->advance(Duration::seconds(31));

        self::assertSame(1, $repository->recoverExpiredAssignments('courses'));
        self::assertNotNull($repository->assignSegment('courses', 0, 'worker-2', Duration::seconds(30)));
    }
}
