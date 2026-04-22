<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Coordination\InMemoryProjectionBucketActivityRepository;
use Ultra\Projection\Coordination\InMemoryProjectionCoordinatorLeaseRepository;
use Ultra\Projection\Coordination\InMemoryProjectionWakeQueue;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\Coordination\WakeProjectionWorker;
use Ultra\Projection\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionDefinition;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;

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

#[CoversClass(ProjectionCoordinator::class)]
final class ProjectionCoordinatorBehaviorTest extends TestCase
{
    public function test_coordinator_queues_worker_wakeups_from_the_scaling_policy_and_acknowledges_processed_buckets(): void
    {
        $projection = new CoordinatorProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $queue = new InMemoryProjectionWakeQueue();
        $runner = new ProjectionRunner($eventStore, $segments);
        $coordinator = new ProjectionCoordinator(
            $catalog,
            $runner,
            $activity,
            $leases,
            $queue,
            new readonly class () implements ProjectionScalingPolicyInterface {
                public function additionalWorkerWakeups(ProjectionDefinition $definition, array $pendingBuckets): int
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

        $result = $coordinator->run('coordinator_projection', 'coordinator-1');

        self::assertTrue($result->acquiredLease);
        self::assertSame(1, $result->processedEvents);
        self::assertSame(1, $result->acknowledgedBuckets);
        self::assertSame(2, $result->queuedWorkerWakeups);
        self::assertSame(['alpha' => 'Math'], $projection->names);
        self::assertInstanceOf(WakeProjectionWorker::class, $queue->dequeue());
        self::assertInstanceOf(WakeProjectionWorker::class, $queue->dequeue());
        self::assertNull($queue->dequeue());
        self::assertFalse($leases->hasLiveCoordinator('coordinator_projection'));
    }

    public function test_coordinator_returns_without_work_when_it_cannot_acquire_the_lease(): void
    {
        $runtime = new ProjectionRuntime(new CoordinatorProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $runner = new ProjectionRunner(new InMemoryEventStore(), new InMemoryProjectionSegmentRepository());
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $queue = new InMemoryProjectionWakeQueue();
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $queue);

        self::assertTrue($leases->acquire('coordinator_projection', 'other-worker'));

        $result = $coordinator->run('coordinator_projection', 'coordinator-1');

        self::assertFalse($result->acquiredLease);
        self::assertSame(0, $result->processedEvents);
        self::assertSame(0, $result->acknowledgedBuckets);
        self::assertSame(0, $result->queuedWorkerWakeups);
        self::assertNull($queue->dequeue());
    }
}
