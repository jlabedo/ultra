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
    public function test_split_of_an_available_segment_creates_two_children_immediately(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');

        $repository->splitSegment('courses', 0);

        $segments = $repository->listSegments('courses');
        self::assertCount(2, $segments);
        self::assertSame(0, $segments[0]->bucketFrom);
        self::assertSame(127, $segments[0]->bucketTo);
        self::assertNull($segments[0]->workerId);
        self::assertSame(128, $segments[1]->bucketFrom);
        self::assertSame(255, $segments[1]->bucketTo);
        self::assertNull($segments[1]->workerId);
    }

    public function test_ensure_initialized_returns_false_when_the_projection_already_exists(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();

        self::assertTrue($repository->ensureInitialized('courses'));
        self::assertFalse($repository->ensureInitialized('courses'));
        $segments = $repository->listSegments('courses');
        self::assertCount(1, $segments);
        self::assertSame(0, $segments[0]->bucketFrom);
        self::assertSame(255, $segments[0]->bucketTo);
        self::assertNull($segments[0]->workerId);
    }

    public function test_split_of_an_active_claim_is_ignored_until_the_claim_is_released(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $segment = $repository->claimSegment('courses', 0, 'worker-1');
        self::assertNotNull($segment);

        $repository->splitSegment('courses', 0);

        $segments = $repository->listSegments('courses');
        self::assertCount(1, $segments);
        self::assertSame(0, $segments[0]->bucketFrom);
        self::assertSame(255, $segments[0]->bucketTo);
        self::assertSame('worker-1', $segments[0]->workerId);

        $repository->completeClaimedSegment($segment, Checkpoint::at(new Sequence(5)), 'worker-1', true);
        $repository->splitSegment('courses', 0);

        $after = $repository->listSegments('courses');
        self::assertCount(2, $after);
        self::assertNull($after[0]->workerId);
        self::assertNull($after[1]->workerId);
    }

    public function test_take_segments_for_range_steals_active_claims_before_merge_replacement(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $repository->splitSegment('courses', 0);
        $left = $repository->claimSegment('courses', 0, 'worker-left');
        $right = $repository->claimSegment('courses', 128, 'worker-right');
        self::assertNotNull($left);
        self::assertNotNull($right);

        $taken = $repository->takeSegmentsForRange('courses', 0, 255, 'coordinator');

        self::assertCount(2, $taken);

        $repository->replaceSegmentsWithMergedSegment('courses', 0, 255, Checkpoint::at(new Sequence(10)));
        $repository->completeClaimedSegment($left, Checkpoint::at(new Sequence(5)), 'worker-left', true);
        $repository->completeClaimedSegment($right, Checkpoint::at(new Sequence(7)), 'worker-right', true);

        $merged_list = $repository->listSegments('courses');
        self::assertCount(1, $merged_list);
        self::assertSame(0, $merged_list[0]->bucketFrom);
        self::assertSame(255, $merged_list[0]->bucketTo);
        self::assertNull($merged_list[0]->workerId);

        $merged = $repository->claimSegment('courses', 0, 'worker-merged');
        self::assertNotNull($merged);
        self::assertSame(10, $merged->checkpoint->gapFloor->value);
    }

    public function test_replace_segments_with_merged_segment_rejects_ranges_that_are_not_fully_covered(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $repository->splitSegment('courses', 0);

        $this->expectException(\LogicException::class);

        $repository->replaceSegmentsWithMergedSegment('courses', 64, 255, Checkpoint::beginning());
    }

    public function test_claim_segment_returns_null_when_the_segment_is_not_available(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');

        self::assertNotNull($repository->claimSegment('courses', 0, 'worker-1'));
        self::assertNull($repository->claimSegment('courses', 0, 'worker-2'));
    }

    public function test_projection_segment_exposes_its_state(): void
    {
        $segment = new ProjectionSegment('courses', 128, 255, Checkpoint::beginning(), 'worker-1');

        self::assertSame('courses', $segment->projectionId);
        self::assertSame(128, $segment->bucketFrom);
        self::assertSame(255, $segment->bucketTo);
        self::assertSame(0, $segment->checkpoint->gapFloor->value);
        self::assertSame('worker-1', $segment->workerId);
    }

    public function test_expired_claims_are_recovered_against_the_injected_clock(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = new InMemoryProjectionSegmentRepository($clock);
        $repository->ensureInitialized('courses');

        self::assertNotNull($repository->claimSegment('courses', 0, 'worker-1', Duration::seconds(30)));

        $clock->sleep(Duration::seconds(31));

        self::assertSame(1, $repository->recoverExpiredClaims('courses'));
        self::assertNotNull($repository->claimSegment('courses', 0, 'worker-2', Duration::seconds(30)));
    }
}
