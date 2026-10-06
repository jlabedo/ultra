<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Sequence;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\TrackingProjection;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class SegmentTopologyTest extends ProjectionTestCase
{
    #[DataProvider('splitStates')]
    public function test_split_creates_unclaimed_children_and_ignores_stale_parent_completion(string $backend, bool $claimed): void
    {
        $repository = $this->fixture($backend)->segments;
        $repository->ensureInitialized('courses');
        $segment = $repository->claimSegment('courses', 0, 'worker-1');
        self::assertNotNull($segment);
        $checkpoint = new Checkpoint(new Sequence(10), [new Sequence(12)]);
        $repository->completeClaimedSegment($segment, $checkpoint, 'worker-1', false);
        if (!$claimed) {
            $repository->releaseClaimedSegment($segment, 'worker-1');
            $segment = null;
        }

        $repository->splitSegment('courses', 0);

        $segments = $repository->listSegments('courses');
        self::assertCount(2, $segments);
        self::assertSame(0, $segments[0]->bucketFrom);
        self::assertSame(127, $segments[0]->bucketTo);
        self::assertNull($segments[0]->workerId);
        self::assertSame(128, $segments[1]->bucketFrom);
        self::assertSame(255, $segments[1]->bucketTo);
        self::assertNull($segments[1]->workerId);
        self::assertSame($checkpoint->serialize(), $segments[0]->checkpoint->serialize());
        self::assertSame($checkpoint->serialize(), $segments[1]->checkpoint->serialize());

        if (null !== $segment) {
            $repository->completeClaimedSegment($segment, Checkpoint::at(new Sequence(5)), 'worker-1', true);
        }

        $after = $repository->listSegments('courses');
        self::assertCount(2, $after);
        self::assertNull($after[0]->workerId);
        self::assertNull($after[1]->workerId);
        self::assertSame($checkpoint->serialize(), $after[0]->checkpoint->serialize());
        self::assertSame($checkpoint->serialize(), $after[1]->checkpoint->serialize());
    }

    #[DataProvider('backends')]
    public function test_merge_replacement_revokes_child_claims_and_preserves_its_checkpoint(string $backend): void
    {
        $repository = $this->fixture($backend)->segments;
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

    #[DataProvider('backends')]
    public function test_merge_rejects_a_range_not_exactly_covered_by_segments(string $backend): void
    {
        $repository = $this->fixture($backend)->segments;
        $repository->ensureInitialized('courses');
        $repository->splitSegment('courses', 0);

        $this->expectException(\LogicException::class);

        $repository->replaceSegmentsWithMergedSegment('courses', 64, 255, Checkpoint::beginning());
    }

    public static function splitStates(): iterable
    {
        foreach (self::backends() as $name => [$backend]) {
            yield $name.' available' => [$backend, false];
            yield $name.' claimed' => [$backend, true];
        }
    }

    #[DataProvider('backends')]
    public function test_merge_catches_up_slower_segments_before_combining_their_ranges(string $backend): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $eventStore = $fixture->store;
        $segments = $fixture->segments;
        $activity = $fixture->activity;
        $runner = $fixture->runner;
        $leftCourseId = self::courseIdInBucketRange(0, 127);
        $rightCourseId = self::courseIdInBucketRange(128, 255);

        $eventStore->append([
            self::courseCreatedEvent($leftCourseId, 'Math'),
            self::courseCreatedEvent($rightCourseId, 'Science'),
        ]);

        $runner->ensureInitialized($runtime);
        $runner->splitSegment('tracking_projection', 0);
        self::assertNotNull($runner->claimSegment('tracking_projection', 128, 'worker-right'));

        $runner->runClaimedOnce($runtime, 'tracking_projection', 128, 'worker-right', limit: 10);

        self::assertSame([$rightCourseId => 'Science'], $projection->names);

        $runner->mergeSegments($runtime, 0, 255, 'coordinator-1', limit: 1);

        self::assertSame([
            $rightCourseId => 'Science',
            $leftCourseId => 'Math',
        ], $projection->names);

        $merged = $runner->claimSegment('tracking_projection', 0, 'worker-merged');
        self::assertNotNull($merged);
        self::assertSame(2, $merged->checkpoint->gapFloor->value);
    }

    #[DataProvider('backends')]
    public function test_merge_preserves_known_sequence_gaps_at_equal_checkpoint_floors(string $backend): void
    {
        $runtime = new ProjectionRuntime(new TrackingProjection(), new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $segments = $fixture->segments;
        $runner = $fixture->runner;

        $runner->ensureInitialized($runtime);
        $runner->splitSegment('tracking_projection', 0);
        $left = $runner->claimSegment('tracking_projection', 0, 'worker-left');
        $right = $runner->claimSegment('tracking_projection', 128, 'worker-right');
        self::assertNotNull($left);
        self::assertNotNull($right);

        $segments->completeClaimedSegment($left, new Checkpoint(new Sequence(10), [new Sequence(12)]), 'worker-left', true);
        $segments->completeClaimedSegment($right, new Checkpoint(new Sequence(10), [new Sequence(13)]), 'worker-right', true);

        $runner->mergeSegments($runtime, 0, 255, 'coordinator-1');

        $merged = $runner->claimSegment('tracking_projection', 0, 'worker-merged');
        self::assertNotNull($merged);
        self::assertSame(10, $merged->checkpoint->gapFloor->value);
        self::assertSame([12, 13], $merged->checkpoint->gapSeqNoValues());
    }
}
