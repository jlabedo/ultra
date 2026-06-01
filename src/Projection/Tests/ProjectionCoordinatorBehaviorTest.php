<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Coordination\InMemory\InMemoryCoordinatorLease;
use Ultra\Coordination\InMemory\InMemoryDirtyKeyTracker;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Time\Duration;

#[EventAttribute('coordinator.course.created')]
final readonly class CoordinatorCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'coordinator_projection', partitionBy: 'courseId')]
final class CoordinatorProjection
{
    /** @var array<string, string> */
    public array $names = [];

    #[ProjectionHandler]
    public function onCourseCreated(CoordinatorCourseCreated $event): void
    {
        $this->names[$event->courseId] = $event->name;
    }
}

final class CountingLeaseRepository implements CoordinatorLease
{
    public int $acquireCalls = 0;
    public int $renewCalls = 0;
    public int $releaseCalls = 0;

    public function acquire(string $coordinatorKey, string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): bool
    {
        ++$this->acquireCalls;

        return true;
    }

    public function renew(string $coordinatorKey, string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): void
    {
        ++$this->renewCalls;
    }

    public function release(string $coordinatorKey, string $workerId): void
    {
        ++$this->releaseCalls;
    }

    public function hasLiveCoordinator(string $coordinatorKey): bool
    {
        return false;
    }

    public function markWakeEnqueued(string $coordinatorKey, Duration $ttl = new Duration(seconds: 30)): bool
    {
        return true;
    }
}

/**
 * Spies on the generic tracker calls a ProjectionActivity makes: `pending()`
 * backs `affected()`, `pendingForChannel()` backs `pendingInSegment()`.
 */
final class CountingDirtyKeyTracker implements DirtyKeyTracker
{
    public int $pendingCalls = 0;
    public int $pendingForChannelCalls = 0;

    public function __construct(private readonly DirtyKeyTracker $inner = new InMemoryDirtyKeyTracker()) {}

    public function bump(string $channel, string $key): void
    {
        $this->inner->bump($channel, $key);
    }

    public function pending(int $limit = 256): array
    {
        ++$this->pendingCalls;

        return $this->inner->pending($limit);
    }

    public function pendingForChannel(string $channel, int $limit = 256): array
    {
        ++$this->pendingForChannelCalls;

        return $this->inner->pendingForChannel($channel, $limit);
    }

    public function drainSeen(string $channel, array $seen): int
    {
        return $this->inner->drainSeen($channel, $seen);
    }
}

/**
 * @internal
 */
#[CoversClass(ProjectionCoordinator::class)]
final class ProjectionCoordinatorBehaviorTest extends TestCase
{
    private const string KEY = ProjectionCoordinator::COORDINATOR_KEY;

    public function test_leader_requests_additional_workers_from_the_scaling_policy_and_acknowledges_processed_buckets(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
        $leases = new InMemoryCoordinatorLease();
        $activator = new InMemoryProjectionActivator();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator(
            $catalog,
            $runner,
            $activity,
            $leases,
            $activator,
            new readonly class implements ProjectionScalingPolicyInterface {
                public function additionalWorkersRequested(array $affectedBuckets): int
                {
                    return 2;
                }
            },
        );

        $eventStore->append([
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'alpha', 'name' => 'Math'],
                domainIds: ['courseId' => 'alpha'],
            ),
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'delta', 'name' => 'Science'],
                domainIds: ['courseId' => 'delta'],
            ),
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'beta', 'name' => 'Physics'],
                domainIds: ['courseId' => 'beta'],
            ),
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

    public function test_non_leader_does_not_poll_or_process_projection_work(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $leases = new InMemoryCoordinatorLease();
        $activator = new InMemoryProjectionActivator();
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);

        self::assertTrue($leases->acquire(self::KEY, 'other-worker'));

        $eventStore->append([
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'alpha', 'name' => 'Math'],
                domainIds: ['courseId' => 'alpha'],
            ),
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

    public function test_coordinator_continues_to_later_buckets_when_an_earlier_bucket_cannot_be_claimed(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
        $leases = new InMemoryCoordinatorLease();
        $activator = new InMemoryProjectionActivator();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);

        $segments->ensureInitialized('coordinator_projection');
        $segments->splitSegment('coordinator_projection', 0);

        $leftBucket = BucketHasher::bucketFor('alpha');
        $rightBucket = BucketHasher::bucketFor('beta');

        self::assertLessThan(128, $leftBucket);
        self::assertGreaterThanOrEqual(128, $rightBucket);

        self::assertNotNull($segments->claimSegment('coordinator_projection', 0, 'other-worker'));

        $eventStore->append([
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'alpha', 'name' => 'Ignored'],
                domainIds: ['courseId' => 'alpha'],
            ),
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'beta', 'name' => 'Physics'],
                domainIds: ['courseId' => 'beta'],
            ),
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

    public function test_coordinator_processes_its_local_claim_until_the_segment_catches_up(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
        $leases = new InMemoryCoordinatorLease();
        $activator = new InMemoryProjectionActivator();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);
        $bucket = BucketHasher::bucketFor('alpha');

        $eventStore->append([
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'alpha', 'name' => 'Math'],
                domainIds: ['courseId' => 'alpha'],
            ),
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'alpha', 'name' => 'Advanced Math'],
                domainIds: ['courseId' => 'alpha'],
            ),
        ]);
        $activity->bump('coordinator_projection', $bucket);

        $firstResult = $coordinator->run('coordinator-1', batchSize: 1, maxCycles: 1);

        self::assertSame(2, $firstResult->processedEvents);
        self::assertSame(1, $firstResult->acknowledgedBuckets);
        self::assertSame([], $activity->pendingInSegment('coordinator_projection', 0, 255));
        self::assertSame(['alpha' => 'Advanced Math'], $projection->names);
    }

    public function test_coordinator_stops_after_the_first_empty_poll_instead_of_spinning_until_max_cycles(): void
    {
        $runtime = new ProjectionRuntime(new CoordinatorProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $leases = new CountingLeaseRepository();
        $tracker = new CountingDirtyKeyTracker();
        $activity = new ProjectionActivity($tracker);
        $activator = new InMemoryProjectionActivator();
        $runner = new ProjectionRunner(new InMemoryEventStore(), new InMemoryProjectionSegmentRepository(), activityRepository: $activity);
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

    public function test_coordinator_stops_after_the_first_unproductive_cycle_when_all_pending_buckets_are_unclaimable(): void
    {
        $runtime = new ProjectionRuntime(new CoordinatorProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $segments = new InMemoryProjectionSegmentRepository();
        $segments->ensureInitialized('coordinator_projection');

        $bucket = BucketHasher::bucketFor('alpha');
        self::assertNotNull($segments->claimSegment('coordinator_projection', 0, 'other-worker'));

        $tracker = new CountingDirtyKeyTracker();
        $tracker->bump('coordinator_projection', (string) $bucket);
        $activity = new ProjectionActivity($tracker);
        $leases = new CountingLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $runner = new ProjectionRunner(new InMemoryEventStore(), $segments, activityRepository: $activity);
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
}
