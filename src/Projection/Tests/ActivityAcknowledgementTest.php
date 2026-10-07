<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\CallbackEventStore;
use Ultra\Projection\Tests\Support\FailingTrackingProjection;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\RuntimeProjection;
use Ultra\Projection\Tests\Support\SecondaryRuntimeProjection;
use Ultra\Projection\Tests\Support\TrackingProjection;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class ActivityAcknowledgementTest extends ProjectionTestCase
{
    #[DataProvider('backends')]
    public function test_partial_batches_keep_the_claim_and_activity_until_caught_up(string $backend): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $eventStore = $fixture->store;
        $segments = $fixture->segments;
        $activity = $fixture->activity;
        $runner = $fixture->runner;
        $bucket = BucketHasher::bucketFor('course-1');

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
            self::courseCreatedEvent('course-1', 'Advanced Math'),
        ]);
        $activity->bump('tracking_projection', $bucket);

        $runner->ensureInitialized($runtime);
        static::assertNotNull($runner->claimSegment('tracking_projection', 0, 'worker-1'));

        $firstResult = $runner->runClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 1);

        static::assertSame(1, $firstResult->processedEvents);
        static::assertFalse($firstResult->caughtUp);
        static::assertSame(0, $firstResult->acknowledgedBuckets);
        static::assertSame('worker-1', $segments->listSegments('tracking_projection')[0]->workerId);
        static::assertCount(1, $activity->pendingInSegment('tracking_projection', 0, 255));

        $secondResult = $runner->runClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 1);

        static::assertSame(1, $secondResult->processedEvents);
        static::assertTrue($secondResult->caughtUp);
        static::assertSame(1, $secondResult->acknowledgedBuckets);
        static::assertNull($segments->listSegments('tracking_projection')[0]->workerId);
        static::assertSame([], $activity->pendingInSegment('tracking_projection', 0, 255));
        static::assertSame(['course-1' => 'Advanced Math'], $projection->names);
    }

    #[DataProvider('backends')]
    public function test_caught_up_acknowledgement_preserves_signals_recorded_after_observation(string $backend): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $innerStore = $fixture->store;
        $segments = $fixture->segments;
        $activity = $fixture->activity;
        $bucket = BucketHasher::bucketFor('course-1');

        $innerStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);
        $activity->bump('tracking_projection', $bucket);

        $runner = new ProjectionRunner(
            new CallbackEventStore($innerStore, static function () use ($activity, $bucket): void {
                $activity->bump('tracking_projection', $bucket);
            }),
            $segments,
            $fixture->transactions,
            activityRepository: $activity,
        );
        $runner->ensureInitialized($runtime);
        static::assertNotNull($runner->claimSegment('tracking_projection', 0, 'worker-1'));

        $result = $runner->runClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 10);
        $pending = $activity->pendingInSegment('tracking_projection', 0, 255);

        static::assertTrue($result->caughtUp);
        static::assertCount(1, $pending);
        static::assertSame($bucket, (int) $pending[0]->key);
        static::assertSame(1, $pending[0]->counter);
    }

    #[DataProvider('backends')]
    public function test_failed_processing_releases_the_claim_without_acknowledging_activity(string $backend): void
    {
        $runtime = new ProjectionRuntime(new FailingTrackingProjection(), new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $eventStore = $fixture->store;
        $segments = $fixture->segments;
        $activity = $fixture->activity;
        $runner = $fixture->runner;
        $bucket = BucketHasher::bucketFor('course-1');

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);
        $activity->bump('failing_tracking_projection', $bucket);

        $runner->ensureInitialized($runtime);
        static::assertNotNull($runner->claimSegment('failing_tracking_projection', 0, 'worker-1'));

        try {
            $runner->runClaimedOnce($runtime, 'failing_tracking_projection', 0, 'worker-1', limit: 10);
            static::fail('Expected the projection handler to throw.');
        } catch (\RuntimeException $e) {
            static::assertSame('Projection handler failed.', $e->getMessage());
        }

        static::assertCount(1, $activity->pendingInSegment('failing_tracking_projection', 0, 255));
        static::assertNull($segments->listSegments('failing_tracking_projection')[0]->workerId);
    }

    #[DataProvider('backends')]
    public function test_unhandled_or_unpartitioned_events_do_not_signal_work(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $runtime = new ProjectionRuntime(new RuntimeProjection(), new UltraNormalizer());
        $activity = $fixture->activity;
        $leases = $fixture->leases;
        $activator = $fixture->activator;
        $recorder = new ProjectionActivityRecorder(new ProjectionCatalog($runtime), $activity, $leases, $activator);

        $recorder->record([
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['studentId' => 'student-1']),
            new Event('unrelated.event', [], ['courseId' => 'course-1']),
        ]);

        static::assertSame([], $activity->pendingInSegment('runtime_projection', 0, 255));
        static::assertSame(0, $activator->workSignals());
    }

    #[DataProvider('backends')]
    public function test_each_matching_bucket_is_signalled_once_per_append_across_projections(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $runtime = new ProjectionRuntime(new RuntimeProjection(), new UltraNormalizer());
        $secondaryRuntime = new ProjectionRuntime(new SecondaryRuntimeProjection(), new UltraNormalizer());
        $activity = $fixture->activity;
        $leases = $fixture->leases;
        $activator = $fixture->activator;
        $recorder = new ProjectionActivityRecorder(
            new ProjectionCatalog($runtime, $secondaryRuntime),
            $activity,
            $leases,
            $activator,
        );

        $recorder->record([
            new Event('unrelated.event', [], ['courseId' => 'course-x']),
            new Event(
                'course.created',
                ['courseId' => 'course-missing', 'name' => 'Ignored'],
                ['studentId' => 'student-1'],
            ),
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math again'], ['courseId' => 'course-1']),
        ]);

        $primaryPending = $activity->pendingInSegment('runtime_projection', 0, 255);
        $secondaryPending = $activity->pendingInSegment('secondary_runtime_projection', 0, 255);

        static::assertCount(1, $primaryPending);
        static::assertSame(1, $primaryPending[0]->counter);
        static::assertCount(1, $secondaryPending);
        static::assertSame(1, $secondaryPending[0]->counter);
        static::assertSame(1, $activator->workSignals());
        static::assertSame([], $activator->workerClaims());
    }

    #[DataProvider('backends')]
    public function test_bucket_observations_preserve_new_signals_and_isolate_projections(string $backend): void
    {
        $activity = $this->fixture($backend)->activity;

        $activity->bump('courses', 42);
        $activity->bump('courses', 42);
        $activity->bump('courses', 50);
        $activity->bump('other_courses', 3);

        $pending = $activity->pendingInSegment('courses', 0, 255);
        $segmentPending = $activity->pendingInSegment('courses', 40, 45);
        $affectedBuckets = $activity->affected();

        static::assertCount(2, $pending);
        static::assertSame(2, $pending[0]->counter);
        static::assertCount(1, $segmentPending);
        static::assertSame(42, (int) $segmentPending[0]->key);
        static::assertCount(3, $affectedBuckets);
        static::assertSame('courses', $affectedBuckets[0]->channel);
        static::assertSame('courses', $affectedBuckets[1]->channel);
        static::assertSame('other_courses', $affectedBuckets[2]->channel);

        $seenCounter = $segmentPending[0]->counter;
        $activity->bump('courses', 42);
        static::assertSame(1, $activity->drainSeen('courses', [42 => $seenCounter]));

        $remainingSegmentPending = $activity->pendingInSegment('courses', 40, 45);

        static::assertCount(1, $remainingSegmentPending);
        static::assertSame(1, $remainingSegmentPending[0]->counter);
        static::assertSame(2, $activity->drainSeen('courses', [42 => 1, 50 => 1]));
        static::assertSame(1, $activity->drainSeen('other_courses', [3 => 1]));
        static::assertSame([], $activity->affected());
    }
}
