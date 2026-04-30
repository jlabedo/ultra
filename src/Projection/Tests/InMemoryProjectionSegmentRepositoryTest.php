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

        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 127, 'workerId' => null],
            ['bucketFrom' => 128, 'bucketTo' => 255, 'workerId' => null],
        ], $repository->listSegments('courses'));
    }

    public function test_ensure_initialized_returns_false_when_the_projection_already_exists(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();

        self::assertTrue($repository->ensureInitialized('courses'));
        self::assertFalse($repository->ensureInitialized('courses'));
        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 255, 'workerId' => null],
        ], $repository->listSegments('courses'));
    }

    public function test_split_of_an_active_claim_is_taken_from_the_worker_immediately(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('courses');
        $segment = $repository->claimSegment('courses', 0, 'worker-1');
        self::assertNotNull($segment);

        $repository->splitSegment('courses', 0);

        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 127, 'workerId' => null],
            ['bucketFrom' => 128, 'bucketTo' => 255, 'workerId' => null],
        ], $repository->listSegments('courses'));

        $repository->completeClaimedSegment($segment, Checkpoint::at(new Sequence(5)), 'worker-1', true);

        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 127, 'workerId' => null],
            ['bucketFrom' => 128, 'bucketTo' => 255, 'workerId' => null],
        ], $repository->listSegments('courses'));
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

        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 255, 'workerId' => null],
        ], $repository->listSegments('courses'));

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
        $segment = new ProjectionSegment('courses', 128, 255, Checkpoint::beginning(), false);

        self::assertSame('courses', $segment->projectionId);
        self::assertSame(128, $segment->bucketFrom);
        self::assertSame(255, $segment->bucketTo);
        self::assertSame(0, $segment->checkpoint->gapFloor->value);
        self::assertFalse($segment->wasCreated);
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
