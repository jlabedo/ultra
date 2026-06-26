<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentStore;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionTopology;
use Ultra\Projection\Tests\Support\BackfillProjection;

/**
 * @internal
 */
#[CoversClass(ProjectionTopology::class)]
final class ProjectionTopologyBackfillTest extends TestCase
{
    public function test_reshape_splits_to_the_requested_canonical_segment_count(): void
    {
        $segments = new InMemoryProjectionSegmentStore();
        $runner = new ProjectionRunner(new InMemoryEventStore(), $segments);
        $topology = new ProjectionTopology(new InMemoryEventStore(), $segments, $segments);
        $runtime = new ProjectionRuntime(new BackfillProjection(), new UltraNormalizer());

        $runner->ensureInitialized($runtime);
        $planned = $topology->reshapeToCanonicalSegments($runtime, 4, 'projection-backfill', limit: 10);

        self::assertSame([0, 64, 128, 192], array_map(static fn ($segment): int => $segment->bucketFrom, $planned));

        $listed = $segments->listSegments('backfill_projection');
        self::assertCount(4, $listed);
        self::assertSame(63, $listed[0]->bucketTo);
        self::assertSame(255, $listed[3]->bucketTo);
    }

    public function test_reshape_merges_down_to_the_requested_canonical_segment_count(): void
    {
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentStore();
        $runner = new ProjectionRunner($eventStore, $segments);
        $topology = new ProjectionTopology($eventStore, $segments, $segments);
        $runtime = new ProjectionRuntime(new BackfillProjection(), new UltraNormalizer());

        $runner->ensureInitialized($runtime);
        $topology->reshapeToCanonicalSegments($runtime, 8, 'projection-backfill');
        $planned = $topology->reshapeToCanonicalSegments($runtime, 2, 'projection-backfill');

        self::assertSame([0, 128], array_map(static fn ($segment): int => $segment->bucketFrom, $planned));
        $listed = $segments->listSegments('backfill_projection');
        self::assertCount(2, $listed);
        self::assertSame(127, $listed[0]->bucketTo);
        self::assertSame(255, $listed[1]->bucketTo);
    }

    public function test_reshape_rejects_active_claims(): void
    {
        $segments = new InMemoryProjectionSegmentStore();
        $runner = new ProjectionRunner(new InMemoryEventStore(), $segments);
        $topology = new ProjectionTopology(new InMemoryEventStore(), $segments, $segments);
        $runtime = new ProjectionRuntime(new BackfillProjection(), new UltraNormalizer());

        $runner->ensureInitialized($runtime);
        self::assertNotNull($segments->claimSegment('backfill_projection', 0, 'worker-1'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be reshaped');

        $topology->reshapeToCanonicalSegments($runtime, 4, 'projection-backfill');
    }

    public function test_reshape_rejects_non_canonical_topology(): void
    {
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentStore();
        $runner = new ProjectionRunner($eventStore, $segments);
        $topology = new ProjectionTopology($eventStore, $segments, $segments);
        $runtime = new ProjectionRuntime(new BackfillProjection(), new UltraNormalizer());

        $runner->ensureInitialized($runtime);
        $topology->split('backfill_projection', 0);
        $topology->split('backfill_projection', 0);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('topology is not canonical');

        $topology->reshapeToCanonicalSegments($runtime, 4, 'projection-backfill');
    }

    public function test_merge_down_drains_segments_before_replacing_them(): void
    {
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentStore();
        $projection = new BackfillProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $runner = new ProjectionRunner($eventStore, $segments);
        $topology = new ProjectionTopology($eventStore, $segments, $segments);

        $eventStore->append([
            new Event(
                eventType: 'backfill.course.created',
                payload: ['courseId' => 'course-1'],
                domainIds: ['courseId' => 'course-1'],
            ),
        ]);

        $runner->ensureInitialized($runtime);
        $topology->reshapeToCanonicalSegments($runtime, 2, 'projection-backfill');
        $bucketFrom = BucketHasher::bucketFor('course-1') < 128 ? 0 : 128;
        $runner->backfillClaimedOnce($runtime, 'backfill_projection', $bucketFrom, 'worker-claimed', limit: 10);
        $topology->reshapeToCanonicalSegments($runtime, 1, 'projection-backfill');

        self::assertSame(['course-1'], $projection->courseIds);
        self::assertCount(1, $segments->listSegments('backfill_projection'));
    }
}

