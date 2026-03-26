<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Ultra\EventStore\EventTopology;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\EventStore\StoredEvent;
use Ultra\Projection\Attribute\PartitionInitializer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection_Postgres\PartitionStrategy;
use Ultra\Projection_Postgres\DeliveryMode;
use Ultra\Projection_Postgres\PostgresProjectionPlan;
use Ultra\Projection_Postgres\ProjectionPlanValidator;

#[Projection('test_projection')]
final class TestProjection
{
    public int $projectionInitializations = 0;

    /**
     * @var list<string>
     */
    public array $initializedPartitions = [];

    #[ProjectionInitializer]
    public function initializeProjection(): void
    {
        ++$this->projectionInitializations;
    }

    #[PartitionInitializer]
    public function initializePartition(string $partitionKey): void
    {
        $this->initializedPartitions[] = $partitionKey;
    }

    #[ProjectionHandler('student.subscribed')]
    public function onStudentSubscribed(StoredEvent $event): void
    {
    }
}

#[Projection('course_projection')]
final class CourseProjection
{
    #[ProjectionHandler('course.created')]
    public function onCourseCreated(StoredEvent $event): void
    {
    }
}

test('domain-id partition strategy produces a stable composite key', function () {
    $strategy = PartitionStrategy::byDomainIds('studentId', 'courseId');
    $event = new StoredEvent(
        eventId: 42,
        eventType: 'student.subscribed',
        payload: [],
        domainIds: ['studentId' => 'student-1', 'courseId' => 'course-101'],
    );

    expect($strategy->keyFor($event))->toBe('{"courseId":"course-101","studentId":"student-1"}');
});

test('validator accepts partitioned plans when all event types expose the partition dimensions', function () {
    $plan = new PostgresProjectionPlan(
        new TestProjection(),
        DeliveryMode::partitioned(PartitionStrategy::byDomainId('studentId')),
    );

    $validator = new ProjectionPlanValidator(new StaticEventTopologyProvider([
        'student.subscribed' => new EventTopology(['courseId', 'studentId']),
    ]));

    expect(fn () => $validator->validate($plan))->not->toThrow(\LogicException::class);
});

test('validator rejects partitioned plans when an event type does not expose the partition dimensions', function () {
    $plan = new PostgresProjectionPlan(
        new CourseProjection(),
        DeliveryMode::partitioned(PartitionStrategy::byDomainId('studentId')),
    );

    $validator = new ProjectionPlanValidator(new StaticEventTopologyProvider([
        'course.created' => new EventTopology(['courseId']),
    ]));

    expect(fn () => $validator->validate($plan))
        ->toThrow(\LogicException::class, 'cannot partition event type "course.created" by [studentId]');
});
