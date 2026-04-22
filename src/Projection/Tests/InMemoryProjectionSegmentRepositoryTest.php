<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Sequence;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionSegment;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

#[CoversClass(InMemoryProjectionSegmentRepository::class)]
#[CoversClass(ProjectionSegment::class)]
final class InMemoryProjectionSegmentRepositoryTest extends TestCase
{
    public function test_split_of_an_available_segment_is_reconciled_into_two_children_with_the_same_checkpoint(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');

        $repository->splitSegment('courses', 0);
        $repository->reconcilePendingOperations('courses');

        $left = $repository->readCurrentState('courses', 0);
        $right = $repository->readCurrentState('courses', 128);

        self::assertSame(0, $left->bucketFrom);
        self::assertSame(127, $left->bucketTo);
        self::assertSame('available', $left->status);
        self::assertSame(0, $left->checkpoint->gapFloor->value);

        self::assertSame(128, $right->bucketFrom);
        self::assertSame(255, $right->bucketTo);
        self::assertSame('available', $right->status);
        self::assertSame(0, $right->checkpoint->gapFloor->value);
    }

    public function test_ensure_initialized_returns_false_when_the_projection_already_exists(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();

        self::assertTrue($repository->ensureInitialized('courses'));
        self::assertFalse($repository->ensureInitialized('courses'));
        self::assertSame(0, $repository->readCurrentState('courses', 0)->checkpoint->gapFloor->value);
    }

    public function test_split_of_an_active_segment_is_deferred_until_reconciliation(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $claimed = $repository->claimSegment('courses', 'worker-1');
        self::assertNotNull($claimed);

        $repository->splitSegment('courses', 0);

        $draining = $repository->readCurrentState('courses', 0);

        self::assertSame('draining', $draining->status);
        self::assertSame('split', $draining->pendingOperation);

        $repository->completeSegment($claimed, Checkpoint::at(new Sequence(5)));
        $repository->reconcilePendingOperations('courses');

        $left = $repository->readCurrentState('courses', 0);
        $right = $repository->readCurrentState('courses', 128);

        self::assertSame('available', $left->status);
        self::assertSame('available', $right->status);
        self::assertSame(5, $left->checkpoint->gapFloor->value);
        self::assertSame(5, $right->checkpoint->gapFloor->value);
    }

    public function test_finalize_merge_waits_for_both_siblings_and_keeps_the_earliest_checkpoint(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $repository->splitSegment('courses', 0);
        $repository->reconcilePendingOperations('courses');
        $repository->requestMerge('courses', 0, 255);

        $left = $repository->readCurrentState('courses', 0);
        $right = $repository->readCurrentState('courses', 128);

        $repository->completeSegment($left, Checkpoint::at(new Sequence(10)));

        $drainedLeft = $repository->readCurrentState('courses', 0);

        self::assertSame('drained', $drainedLeft->status);
        self::assertSame(10, $drainedLeft->checkpoint->gapFloor->value);

        $repository->completeSegment($right, Checkpoint::at(new Sequence(7)));
        $repository->reconcilePendingOperations('courses');

        $merged = $repository->readCurrentState('courses', 0);

        self::assertSame(0, $merged->bucketFrom);
        self::assertSame(255, $merged->bucketTo);
        self::assertSame('available', $merged->status);
        self::assertSame(7, $merged->checkpoint->gapFloor->value);

        try {
            $repository->readCurrentState('courses', 128);
            self::fail('Expected the drained sibling segment to be removed after merge finalization.');
        } catch (LogicException $e) {
            self::assertStringContainsString('Segment [128] not found', $e->getMessage());
        }
    }

    public function test_claim_segment_skips_non_available_segments_and_claims_the_next_one(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $repository->splitSegment('courses', 0);
        $repository->reconcilePendingOperations('courses');

        $first = $repository->claimSegmentForBucket('courses', 40, 'worker-1');
        $second = $repository->claimSegment('courses', 'worker-2');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(0, $first->bucketFrom);
        self::assertSame(128, $second->bucketFrom);
    }

    public function test_claim_segment_for_bucket_returns_null_when_the_matching_segment_is_not_available(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $repository->splitSegment('courses', 0);
        $repository->reconcilePendingOperations('courses');

        $claimed = $repository->claimSegmentForBucket('courses', 200, 'worker-1');
        $retry = $repository->claimSegmentForBucket('courses', 200, 'worker-2');

        self::assertNotNull($claimed);
        self::assertNull($retry);
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

    public function test_finalize_split_rejects_a_minimum_granularity_segment(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be split');

        $repository->finalizeSplit(
            new ProjectionSegment('courses', 0, 0, 'available', null, Checkpoint::beginning(), false),
            Checkpoint::beginning(),
        );
    }

    public function test_expired_segment_leases_are_recovered_against_the_injected_clock(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = new InMemoryProjectionSegmentRepository($clock);
        $repository->ensureInitialized('courses');

        $claimed = $repository->claimSegment('courses', 'worker-1', Duration::seconds(30));

        self::assertNotNull($claimed);

        $clock->advance(Duration::seconds(31));

        self::assertSame(1, $repository->recoverExpiredLeases('courses'));

        $reclaimed = $repository->claimSegment('courses', 'worker-2', Duration::seconds(30));

        self::assertNotNull($reclaimed);
        self::assertSame('active', $reclaimed->status);
    }
}
