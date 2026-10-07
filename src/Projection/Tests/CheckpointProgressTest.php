<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\Event;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\TrackingProjection;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class CheckpointProgressTest extends ProjectionTestCase
{
    #[DataProvider('backends')]
    public function test_backfill_advances_batched_checkpoints_without_activity_hints(string $backend): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $eventStore = $fixture->store;
        $segments = $fixture->segments;
        $activity = $fixture->activity;
        $runner = $fixture->runner;

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
            self::courseCreatedEvent('course-2', 'Science'),
        ]);

        $runner->ensureInitialized($runtime);
        $firstResult = $runner->backfillClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 1);
        $secondResult = $runner->backfillClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 1);

        static::assertSame(1, $firstResult->processedEvents);
        static::assertFalse($firstResult->caughtUp);
        static::assertSame(1, $secondResult->processedEvents);
        static::assertTrue($secondResult->caughtUp);
        static::assertSame(
            [
                'course-1' => 'Math',
                'course-2' => 'Science',
            ],
            $projection->names,
        );
        static::assertSame([], $activity->affected());
        static::assertNull($segments->listSegments('tracking_projection')[0]->workerId);
    }

    #[DataProvider('backends')]
    public function test_replay_resets_existing_state_and_is_repeatable_from_event_zero(string $backend): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $eventStore = $fixture->store;
        $segments = $fixture->segments;
        $runner = $fixture->runner;

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
            self::courseCreatedEvent('course-2', 'Science'),
            self::courseCreatedEvent('course-3', 'History'),
        ]);

        // Prime the projection incrementally so there's existing state +
        // checkpoint to wipe.
        $runner->ensureInitialized($runtime);
        $runner->backfillClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 100);
        static::assertSame(
            ['course-1' => 'Math', 'course-2' => 'Science', 'course-3' => 'History'],
            $projection->names,
        );
        $resetsBefore = $projection->resets;

        // Replay from event 0.
        $processed = $runner->replayFromBeginning($runtime, limit: 2);

        static::assertSame(3, $processed);
        static::assertSame($resetsBefore + 1, $projection->resets, 'replay must reset projection state');
        static::assertSame(
            ['course-1' => 'Math', 'course-2' => 'Science', 'course-3' => 'History'],
            $projection->names,
        );
        static::assertSame(3, $runner->replayFromBeginning($runtime, limit: 2));
        static::assertSame(
            ['course-1' => 'Math', 'course-2' => 'Science', 'course-3' => 'History'],
            $projection->names,
        );

        // Every segment should be caught up (no claims left over).
        foreach ($segments->listSegments('tracking_projection') as $segment) {
            static::assertNull($segment->workerId);
        }
    }

    #[DataProvider('backends')]
    public function test_empty_replay_initializes_a_projection_without_processing_events(string $backend): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $runner = $this->fixture($backend)->runner;

        static::assertSame(0, $runner->replayFromBeginning($runtime));
        static::assertSame([], $projection->names);
        static::assertSame(1, $projection->initialized);
    }

    #[DataProvider('backends')]
    public function test_a_restarted_runner_applies_only_new_events_in_partition_order(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $fixture->store->append([self::courseCreatedEvent('projection-resume-course', 'First')]);

        $first = $fixture->runner->backfillClaimedOnce($runtime, 'tracking_projection', 0, 'first-worker');
        static::assertSame(1, $first->processedEvents);
        static::assertSame(1, $fixture->segments->listSegments('tracking_projection')[0]->checkpoint->gapFloor->value);
        $fixture->store->append([self::courseCreatedEvent('projection-resume-course', 'Second')]);
        $restarted = new ProjectionRunner(
            $fixture->store,
            $fixture->segments,
            $fixture->transactions,
            $fixture->activity,
        );

        $second = $restarted->runClaimedOnce($runtime, 'tracking_projection', 0, 'second-worker');

        static::assertSame(1, $second->processedEvents);
        static::assertTrue($second->caughtUp);
        static::assertSame(['First', 'Second'], $projection->applied);
        static::assertSame(['projection-resume-course' => 'Second'], $projection->names);
        static::assertSame(2, $projection->flushes);
        $segment = $fixture->segments->listSegments('tracking_projection')[0];
        static::assertSame(2, $segment->checkpoint->gapFloor->value);
        static::assertNull($segment->workerId);
    }

    #[DataProvider('backends')]
    public function test_a_segment_applies_only_its_partition_range_and_handled_event_types(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $left = self::courseIdInBucketRange(0, 127);
        $right = self::courseIdInBucketRange(128, 255);
        $fixture->store->append([
            self::courseCreatedEvent($right, 'Other partition'),
            new Event('unhandled.projection-event', [], ['courseId' => $left]),
            self::courseCreatedEvent($left, 'First'),
            self::courseCreatedEvent($left, 'Second'),
        ]);
        $fixture->runner->ensureInitialized($runtime);
        $fixture->runner->splitSegment('tracking_projection', 0);

        $result = $fixture->runner->backfillClaimedOnce($runtime, 'tracking_projection', 0, 'left-worker');

        static::assertSame(2, $result->processedEvents);
        static::assertSame(['First', 'Second'], $projection->applied);
        static::assertSame([$left => 'Second'], $projection->names);
        static::assertSame(0, $fixture->segments->listSegments('tracking_projection')[1]->checkpoint->gapFloor->value);
    }
}
