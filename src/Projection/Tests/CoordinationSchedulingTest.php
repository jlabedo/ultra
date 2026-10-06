<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\Coordination\Tracking\PendingKey;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Coordination\DefaultProjectionScalingPolicy;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\CoordinatorProjection;
use Ultra\Projection\Tests\Support\CountingDirtyKeyTracker;
use Ultra\Projection\Tests\Support\CountingLeaseRepository;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\RuntimeProjection;
use Ultra\Projection\Tests\Support\SecondaryRuntimeProjection;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class CoordinationSchedulingTest extends ProjectionTestCase
{
    private const string KEY = ProjectionCoordinator::COORDINATOR_KEY;

    public function test_scaling_claims_remote_work_while_the_leader_processes_its_own_segment(): void
    {
        $fixture = $this->fixture('memory');
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = $fixture->store;
        $segments = $fixture->segments;
        $activity = $fixture->activity;
        $leases = $fixture->leases;
        $activator = $fixture->activator;
        $coordinator = $fixture->coordinator(
            $runtime,
            new readonly class implements ProjectionScalingPolicyInterface {
                public function additionalWorkersRequested(array $affectedBuckets): int
                {
                    return 2;
                }
            },
        );

        $eventStore->append([
            self::courseEvent('alpha', 'Math'),
            self::courseEvent('delta', 'Science'),
            self::courseEvent('beta', 'Physics'),
        ]);

        $activity->bump('coordinator_projection', BucketHasher::bucketFor('alpha'));
        $activity->bump('coordinator_projection', BucketHasher::bucketFor('delta'));
        $activity->bump('coordinator_projection', BucketHasher::bucketFor('beta'));

        $result = $coordinator->run('coordinator-1');

        self::assertTrue($result->acquiredLease);
        self::assertSame(1, $result->processedEvents);
        self::assertSame(1, $result->acknowledgedBuckets);
        self::assertSame(2, $result->requestedWorkerActivations);
        self::assertSame(['alpha' => 'Math'], $projection->names);
        self::assertCount(3, $segments->listSegments('coordinator_projection'));
        self::assertCount(2, $activator->workerClaims());
        self::assertFalse($leases->hasLiveCoordinator(self::KEY));
    }

    public function test_an_existing_leader_prevents_a_contender_from_processing_work(): void
    {
        $fixture = $this->fixture('memory');
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = $fixture->store;
        $activity = $fixture->activity;
        $leases = $fixture->leases;
        $activator = $fixture->activator;
        $coordinator = $fixture->coordinator($runtime);

        self::assertTrue($leases->acquire(self::KEY, 'other-worker'));

        $eventStore->append([
            self::courseEvent('alpha', 'Math'),
        ]);
        $activity->bump('coordinator_projection', BucketHasher::bucketFor('alpha'));

        $result = $coordinator->run('coordinator-1');

        self::assertFalse($result->acquiredLease);
        self::assertSame(0, $result->processedEvents);
        self::assertSame(0, $result->acknowledgedBuckets);
        self::assertSame(0, $result->requestedWorkerActivations);
        self::assertSame([], $projection->names);
        self::assertSame([], $activator->workerClaims());
    }

    public function test_an_owned_earlier_segment_does_not_starve_later_pending_segments(): void
    {
        $fixture = $this->fixture('memory');
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = $fixture->store;
        $segments = $fixture->segments;
        $activity = $fixture->activity;
        $coordinator = $fixture->coordinator($runtime);

        $segments->ensureInitialized('coordinator_projection');
        $segments->splitSegment('coordinator_projection', 0);

        $leftBucket = BucketHasher::bucketFor('alpha');
        $rightBucket = BucketHasher::bucketFor('beta');

        self::assertLessThan(128, $leftBucket);
        self::assertGreaterThanOrEqual(128, $rightBucket);

        self::assertNotNull($segments->claimSegment('coordinator_projection', 0, 'other-worker'));

        $eventStore->append([
            self::courseEvent('alpha', 'Ignored'),
            self::courseEvent('beta', 'Physics'),
        ]);

        $activity->bump('coordinator_projection', $leftBucket);
        $activity->bump('coordinator_projection', $rightBucket);

        $result = $coordinator->run('coordinator-1');

        self::assertTrue($result->acquiredLease);
        self::assertSame(1, $result->processedEvents);
        self::assertSame(1, $result->acknowledgedBuckets);
        self::assertSame(['beta' => 'Physics'], $projection->names);

        $remaining = $activity->pendingInSegment('coordinator_projection', 0, 255);

        self::assertCount(1, $remaining);
        self::assertSame($leftBucket, (int) $remaining[0]->key);
    }

    public function test_the_leader_finishes_its_local_batches_before_the_next_cycle(): void
    {
        $fixture = $this->fixture('memory');
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = $fixture->store;
        $activity = $fixture->activity;
        $coordinator = $fixture->coordinator($runtime);
        $bucket = BucketHasher::bucketFor('alpha');

        $eventStore->append([
            self::courseEvent('alpha', 'Math'),
            self::courseEvent('alpha', 'Advanced Math'),
        ]);
        $activity->bump('coordinator_projection', $bucket);

        $firstResult = $coordinator->run('coordinator-1', batchSize: 1, maxCycles: 1);

        self::assertSame(2, $firstResult->processedEvents);
        self::assertSame(1, $firstResult->acknowledgedBuckets);
        self::assertSame([], $activity->pendingInSegment('coordinator_projection', 0, 255));
        self::assertSame(['alpha' => 'Advanced Math'], $projection->names);
    }

    public function test_an_empty_poll_stops_and_releases_the_leader_lease(): void
    {
        $fixture = $this->fixture('memory');
        $runtime = new ProjectionRuntime(new CoordinatorProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $leases = new CountingLeaseRepository();
        $tracker = new CountingDirtyKeyTracker();
        $activity = new ProjectionActivity($tracker);
        $activator = $fixture->activator;
        $runner = new ProjectionRunner($fixture->store, $fixture->segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);

        $result = $coordinator->run('coordinator-1', maxCycles: 3);

        self::assertTrue($result->acquiredLease);
        self::assertSame(0, $result->processedEvents);
        self::assertSame(0, $result->acknowledgedBuckets);
        self::assertSame(0, $result->requestedWorkerActivations);
        self::assertSame(1, $leases->acquireCalls);
        self::assertSame(1, $leases->renewCalls);
        self::assertSame(1, $leases->releaseCalls);
        self::assertSame(1, $tracker->pendingCalls);
        self::assertSame(0, $tracker->pendingForChannelCalls);
        self::assertSame([], $activator->workerClaims());
    }

    public function test_an_unproductive_cycle_stops_without_spinning_on_owned_segments(): void
    {
        $fixture = $this->fixture('memory');
        $runtime = new ProjectionRuntime(new CoordinatorProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $segments = $fixture->segments;
        $segments->ensureInitialized('coordinator_projection');

        $bucket = BucketHasher::bucketFor('alpha');
        self::assertNotNull($segments->claimSegment('coordinator_projection', 0, 'other-worker'));

        $tracker = new CountingDirtyKeyTracker();
        $tracker->bump('coordinator_projection', (string) $bucket);
        $activity = new ProjectionActivity($tracker);
        $leases = new CountingLeaseRepository();
        $activator = $fixture->activator;
        $runner = new ProjectionRunner($fixture->store, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);

        $result = $coordinator->run('coordinator-1', maxCycles: 3);

        self::assertTrue($result->acquiredLease);
        self::assertSame(0, $result->processedEvents);
        self::assertSame(0, $result->acknowledgedBuckets);
        self::assertSame(0, $result->requestedWorkerActivations);
        self::assertSame(1, $tracker->pendingCalls);
        self::assertSame(1, $leases->renewCalls);
        self::assertSame(1, $leases->releaseCalls);
        self::assertSame(0, $tracker->pendingForChannelCalls);
    }

    public function test_one_global_leader_catches_up_and_acknowledges_multiple_projections(): void
    {
        $primaryProjection = new RuntimeProjection();
        $secondaryProjection = new SecondaryRuntimeProjection();
        $container = $this->projectionUltraLight()
            ->withService($primaryProjection, RuntimeProjection::class)
            ->withService($secondaryProjection, SecondaryRuntimeProjection::class)
            ->boot()
        ;
        $eventStore = $container->eventStore();
        $activity = $container->projectionActivity();
        $coordinator = $container->projectionCoordinator();

        $events = [
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
            new Event('course.created', ['courseId' => 'course-2', 'name' => 'Physics'], ['courseId' => 'course-2']),
        ];

        $eventStore->append($events);

        $result = $coordinator->run('coordinator-1');

        self::assertTrue($result->acquiredLease);
        self::assertSame(4, $result->processedEvents);
        self::assertSame(4, $result->acknowledgedBuckets);
        self::assertSame(['course-1' => 'Math', 'course-2' => 'Physics'], $primaryProjection->names);
        self::assertSame([], $activity->pendingInSegment('runtime_projection', 0, 255));
        self::assertSame([], $activity->pendingInSegment('secondary_runtime_projection', 0, 255));
    }

    public function test_default_scaling_keeps_work_on_the_leader(): void
    {
        $policy = new DefaultProjectionScalingPolicy();

        self::assertSame(0, $policy->additionalWorkersRequested(
            [new PendingKey('runtime_projection', '12', 3)],
        ));
    }

    private static function courseEvent(string $id, string $name): Event
    {
        return new Event('coordinator.course.created', ['courseId' => $id, 'name' => $name], ['courseId' => $id]);
    }

}
