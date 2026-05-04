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

#[Projection(name: 'unpartitioned_coordinator_projection')]
final class UnpartitionedCoordinatorProjection
{
    /** @var list<string> */
    public array $handled = [];

    #[ProjectionHandler]
    public function onCourseCreated(CoordinatorCourseCreated $event): void
    {
        $this->handled[] = $event->courseId.':'.$event->name;
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

    public function pendingBucketsForSegment(string $projectionId, int $bucketFrom, int $bucketTo): array
    {
        ++$this->pendingBucketsCalls;

        return [];
    }

    public function decrementSeenBuckets(string $projectionId, array $seenCounters): int
    {
        return 0;
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

    public function pendingBucketsForSegment(string $projectionId, int $bucketFrom, int $bucketTo): array
    {
        ++$this->pendingBucketsCalls;

        return $this->inner->pendingBucketsForSegment($projectionId, $bucketFrom, $bucketTo);
    }

    public function decrementSeenBuckets(string $projectionId, array $seenCounters): int
    {
        return $this->inner->decrementSeenBuckets($projectionId, $seenCounters);
    }
}

/**
 * @internal
 */
#[CoversClass(ProjectionCoordinator::class)]
final class ProjectionCoordinatorBehaviorTest extends TestCase
{
    public function test_worker_pulls_pending_projection_work_without_remote_assignments(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator(
            $catalog,
            $runner,
            $activity,
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
        self::assertSame(3, $result->processedEvents);
        self::assertSame(3, $result->acknowledgedBuckets);
        self::assertSame([
            'alpha' => 'Math',
            'delta' => 'Science',
            'beta' => 'Physics',
        ], $projection->names);
        self::assertCount(3, $segments->listSegments('coordinator_projection'));
        self::assertFalse($leases->hasLiveCoordinator());
    }

    public function test_worker_processes_work_even_when_the_obsolete_global_leader_lease_is_held(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity);

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
        self::assertSame(1, $result->processedEvents);
        self::assertSame(1, $result->acknowledgedBuckets);
        self::assertSame(['alpha' => 'Math'], $projection->names);
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
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity);

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
        self::assertSame(1, $result->processedEvents);
        self::assertSame(1, $result->acknowledgedBuckets);
        self::assertSame(['beta' => 'Physics'], $projection->names);

        $remaining = $activity->pendingBucketsForSegment('coordinator_projection', 0, 255);

        self::assertCount(1, $remaining);
        self::assertSame($leftBucket, $remaining[0]->bucket);
    }

    public function test_coordinator_processes_its_local_claim_until_the_segment_catches_up(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity);
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
        self::assertSame([], $activity->pendingBucketsForSegment('coordinator_projection', 0, 255));
        self::assertSame(['alpha' => 'Advanced Math'], $projection->names);
    }

    public function test_unpartitioned_projection_uses_one_ordered_lane(): void
    {
        $projection = new UnpartitionedCoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity);

        $eventStore->append([
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'alpha', 'name' => 'Math'],
                domainIds: ['eventId' => 'event-1'],
            ),
            new Event(
                eventType: 'coordinator.course.created',
                payload: ['courseId' => 'beta', 'name' => 'Physics'],
                domainIds: ['eventId' => 'event-2'],
            ),
        ]);
        $activity->bump('unpartitioned_coordinator_projection', 0);

        $result = $coordinator->run('worker-1', batchSize: 1, maxCycles: 1);

        self::assertSame(2, $result->processedEvents);
        self::assertSame(1, $result->acknowledgedBuckets);
        self::assertSame(['alpha:Math', 'beta:Physics'], $projection->handled);
        self::assertCount(1, $segments->listSegments('unpartitioned_coordinator_projection'));
    }

    public function test_coordinator_stops_after_the_first_empty_poll_instead_of_spinning_until_max_cycles(): void
    {
        $runtime = new ProjectionRuntime(new CoordinatorProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $leases = new CountingLeaseRepository();
        $activity = new EmptyAfterFirstPollActivityRepository();
        $runner = new ProjectionRunner(new InMemoryEventStore(), new InMemoryProjectionSegmentRepository(), activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity);

        $result = $coordinator->run('coordinator-1', maxCycles: 3);
        self::assertSame(0, $result->processedEvents);
        self::assertSame(0, $result->acknowledgedBuckets);
        self::assertSame(0, $leases->acquireCalls);
        self::assertSame(0, $leases->renewCalls);
        self::assertSame(0, $leases->releaseCalls);
        self::assertSame(1, $activity->affectedBucketsCalls);
        self::assertSame(0, $activity->pendingBucketsCalls);
    }

    public function test_coordinator_stops_after_the_first_unproductive_cycle_when_all_pending_buckets_are_unclaimable(): void
    {
        $runtime = new ProjectionRuntime(new CoordinatorProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $segments = new InMemoryProjectionSegmentRepository();
        $segments->ensureInitialized('coordinator_projection');

        $bucket = BucketHasher::bucketFor('alpha');
        self::assertNotNull($segments->claimSegment('coordinator_projection', 0, 'other-worker'));

        $innerActivity = new InMemoryProjectionBucketActivityRepository();
        $innerActivity->bump('coordinator_projection', $bucket);
        $activity = new CountingActivityRepository($innerActivity);
        $leases = new CountingLeaseRepository();
        $runner = new ProjectionRunner(new InMemoryEventStore(), $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity);

        $result = $coordinator->run('coordinator-1', maxCycles: 3);
        self::assertSame(0, $result->processedEvents);
        self::assertSame(0, $result->acknowledgedBuckets);
        self::assertSame(1, $activity->affectedBucketsCalls);
        self::assertSame(0, $leases->renewCalls);
        self::assertSame(0, $leases->releaseCalls);
        self::assertSame(0, $activity->pendingBucketsCalls);
    }
}
