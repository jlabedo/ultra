<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Coordination\DefaultProjectionScalingPolicy;
use Ultra\Projection\Coordination\InMemoryProjectionBucketActivityRepository;
use Ultra\Projection\Coordination\InMemoryProjectionCoordinatorLeaseRepository;
use Ultra\Projection\Coordination\InMemoryProjectionWakeQueue;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\WakeProjectionCoordinator;
use Ultra\Projection\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionPlan;
use Ultra\Projection\ProjectionRunner;

#[EventAttribute('course.created')]
final readonly class RuntimeCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'runtime_projection', partitionBy: 'courseId')]
final class RuntimeProjection
{
    /** @var array<string, string> */
    public array $names = [];

    #[ProjectionHandler]
    public function onCourseCreated(RuntimeCourseCreated $event): void
    {
        $this->names[$event->courseId] = $event->name;
    }
}

test('activity recorder deduplicates buckets per append and enqueues a single coordinator wakeup', function (): void {
    $projection = new RuntimeProjection();
    $plan = new ProjectionPlan($projection, new UltraNormalizer());
    $catalog = new ProjectionCatalog($plan);
    $activity = new InMemoryProjectionBucketActivityRepository();
    $leases = new InMemoryProjectionCoordinatorLeaseRepository();
    $queue = new InMemoryProjectionWakeQueue();
    $recorder = new ProjectionActivityRecorder($catalog, $activity, $leases, $queue);

    $recorder->record([
        new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
        new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math again'], ['courseId' => 'course-1']),
    ]);

    $pending = $activity->pendingBuckets('runtime_projection');

    expect($pending)->toHaveCount(1)
        ->and($pending[0]->counter)->toBe(1)
        ->and($queue->dequeue())->toBeInstanceOf(WakeProjectionCoordinator::class)
        ->and($queue->dequeue())->toBeNull();
});

test('coordinator catches up a stale projection and acknowledges processed buckets', function (): void {
    $projection = new RuntimeProjection();
    $plan = new ProjectionPlan($projection, new UltraNormalizer());
    $catalog = new ProjectionCatalog($plan);
    $eventStore = new InMemoryEventStore();
    $segments = new InMemoryProjectionSegmentRepository();
    $activity = new InMemoryProjectionBucketActivityRepository();
    $leases = new InMemoryProjectionCoordinatorLeaseRepository();
    $queue = new InMemoryProjectionWakeQueue();
    $recorder = new ProjectionActivityRecorder($catalog, $activity, $leases, $queue);
    $runner = new ProjectionRunner($eventStore, $segments);
    $coordinator = new ProjectionCoordinator(
        $catalog,
        $runner,
        $activity,
        $leases,
        $queue,
        new DefaultProjectionScalingPolicy(),
    );

    $events = [
        new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
        new Event('course.created', ['courseId' => 'course-2', 'name' => 'Physics'], ['courseId' => 'course-2']),
    ];

    $eventStore->append($events);
    $recorder->record($events);

    expect($queue->dequeue())->toBeInstanceOf(WakeProjectionCoordinator::class);

    $result = $coordinator->run('runtime_projection', 'coordinator-1');

    expect($result->acquiredLease)->toBeTrue()
        ->and($result->processedEvents)->toBe(2)
        ->and($projection->names)->toBe([
            'course-1' => 'Math',
            'course-2' => 'Physics',
        ])
        ->and($activity->pendingBuckets('runtime_projection'))->toBe([]);
});
