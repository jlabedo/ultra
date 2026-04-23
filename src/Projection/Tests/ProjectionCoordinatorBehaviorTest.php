<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\InMemory\InMemoryProjectionBucketActivityRepository;
use Ultra\Projection\InMemory\InMemoryProjectionCoordinatorLeaseRepository;
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

final class CountingLeaseRepository implements ProjectionCoordinatorLeaseRepositoryInterface
{
    public int $acquireCalls = 0;
    public int $renewCalls = 0;
    public int $releaseCalls = 0;

    public function acquire(string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): bool
    {
        ++$this->acquireCalls;

        return true;
    }

    public function renew(string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): void
    {
        ++$this->renewCalls;
    }

    public function release(string $workerId): void
    {
        ++$this->releaseCalls;
    }

    public function hasLiveCoordinator(): bool
    {
        return false;
    }

    public function markWakeEnqueued(Duration $ttl = new Duration(seconds: 30)): bool
    {
        return true;
    }
}

final class EmptyAfterFirstPollActivityRepository implements ProjectionBucketActivityRepositoryInterface
{
    public int $affectedBucketsCalls = 0;
    public int $pendingBucketsCalls = 0;

    public function bump(string $projectionId, int $bucket): void {}

    public function affectedBuckets(int $limit = 256): array
    {
        ++$this->affectedBucketsCalls;

        return [];
    }

    public function pendingBuckets(string $projectionId, int $limit = 32): array
    {
        ++$this->pendingBucketsCalls;

        return [];
    }

    public function acknowledge(string $projectionId, int $bucket, int $counter): void
    {
    }
}

final class CountingActivityRepository implements ProjectionBucketActivityRepositoryInterface
{
    public int $affectedBucketsCalls = 0;
    public int $pendingBucketsCalls = 0;

    public function __construct(
        private readonly ProjectionBucketActivityRepositoryInterface $inner,
    ) {}

    public function bump(string $projectionId, int $bucket): void
    {
        $this->inner->bump($projectionId, $bucket);
    }

    public function affectedBuckets(int $limit = 256): array
    {
        ++$this->affectedBucketsCalls;

        return $this->inner->affectedBuckets($limit);
    }

    public function pendingBuckets(string $projectionId, int $limit = 32): array
    {
        ++$this->pendingBucketsCalls;

        return $this->inner->pendingBuckets($projectionId, $limit);
    }

    public function acknowledge(string $projectionId, int $bucket, int $counter): void
    {
        $this->inner->acknowledge($projectionId, $bucket, $counter);
    }
}

#[CoversClass(ProjectionCoordinator::class)]
final class ProjectionCoordinatorBehaviorTest extends TestCase
{
    public function test_leader_requests_additional_workers_from_the_scaling_policy_and_acknowledges_processed_buckets(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $runner = new ProjectionRunner($eventStore, $segments);
        $coordinator = new ProjectionCoordinator(
            $catalog,
            $runner,
            $activity,
            $leases,
            $activator,
            new readonly class () implements ProjectionScalingPolicyInterface {
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
        ]);

        $activity->bump('coordinator_projection', BucketHasher::bucketFor('alpha'));

        $result = $coordinator->run('coordinator-1');

        self::assertTrue($result->acquiredLease);
        self::assertSame(1, $result->processedEvents);
        self::assertSame(1, $result->acknowledgedBuckets);
        self::assertSame(2, $result->requestedWorkerActivations);
        self::assertSame(['alpha' => 'Math'], $projection->names);
        self::assertSame([2], $activator->workerRequests());
        self::assertFalse($leases->hasLiveCoordinator());
    }

    public function test_worker_still_processes_work_when_it_cannot_acquire_the_leader_lease(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner($eventStore, $segments);
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);

        self::assertTrue($leases->acquire('other-worker'));

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
        self::assertSame(1, $result->processedEvents);
        self::assertSame(1, $result->acknowledgedBuckets);
        self::assertSame(0, $result->requestedWorkerActivations);
        self::assertSame(['alpha' => 'Math'], $projection->names);
        self::assertSame([], $activator->workerRequests());
    }

    public function test_coordinator_continues_to_later_buckets_when_an_earlier_bucket_cannot_be_claimed(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $runner = new ProjectionRunner($eventStore, $segments);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);

        $segments->ensureInitialized('coordinator_projection');
        $segments->splitSegment('coordinator_projection', 0);
        $segments->reconcilePendingOperations('coordinator_projection');

        $leftBucket = BucketHasher::bucketFor('alpha');
        $rightBucket = BucketHasher::bucketFor('beta');

        self::assertLessThan(128, $leftBucket);
        self::assertGreaterThanOrEqual(128, $rightBucket);

        $segments->claimSegmentForBucket('coordinator_projection', $leftBucket, 'other-worker');

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

        $remaining = $activity->pendingBuckets('coordinator_projection');

        self::assertCount(1, $remaining);
        self::assertSame($leftBucket, $remaining[0]->bucket);
    }

    public function test_coordinator_stops_after_the_first_empty_poll_instead_of_spinning_until_max_cycles(): void
    {
        $runtime = new ProjectionRuntime(new CoordinatorProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $leases = new CountingLeaseRepository();
        $activity = new EmptyAfterFirstPollActivityRepository();
        $activator = new InMemoryProjectionActivator();
        $runner = new ProjectionRunner(new InMemoryEventStore(), new InMemoryProjectionSegmentRepository());
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);

        $result = $coordinator->run('coordinator-1', maxCycles: 3);

        self::assertTrue($result->acquiredLease);
        self::assertSame(0, $result->processedEvents);
        self::assertSame(0, $result->acknowledgedBuckets);
        self::assertSame(0, $result->requestedWorkerActivations);
        self::assertSame(1, $leases->acquireCalls);
        self::assertSame(1, $leases->renewCalls);
        self::assertSame(1, $leases->releaseCalls);
        self::assertSame(1, $activity->affectedBucketsCalls);
        self::assertSame(0, $activity->pendingBucketsCalls);
        self::assertSame([], $activator->workerRequests());
    }

    public function test_coordinator_stops_after_the_first_unproductive_cycle_when_all_pending_buckets_are_unclaimable(): void
    {
        $runtime = new ProjectionRuntime(new CoordinatorProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $segments = new InMemoryProjectionSegmentRepository();
        $segments->ensureInitialized('coordinator_projection');

        $bucket = BucketHasher::bucketFor('alpha');
        $segments->claimSegmentForBucket('coordinator_projection', $bucket, 'other-worker');

        $innerActivity = new InMemoryProjectionBucketActivityRepository();
        $innerActivity->bump('coordinator_projection', $bucket);
        $activity = new CountingActivityRepository($innerActivity);
        $leases = new CountingLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $runner = new ProjectionRunner(new InMemoryEventStore(), $segments);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);

        $result = $coordinator->run('coordinator-1', maxCycles: 3);

        self::assertTrue($result->acquiredLease);
        self::assertSame(0, $result->processedEvents);
        self::assertSame(0, $result->acknowledgedBuckets);
        self::assertSame(0, $result->requestedWorkerActivations);
        self::assertSame(1, $activity->affectedBucketsCalls);
        self::assertSame(1, $leases->renewCalls);
        self::assertSame(1, $leases->releaseCalls);
        self::assertSame(0, $activity->pendingBucketsCalls);
    }
}
