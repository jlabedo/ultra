<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Ultra\EventStore\EventTopology;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\EventStore\StoredEvent;
use Ultra\Projection\PartitionStrategy;
use Ultra\Projection\Projection;
use Ultra\Projection\ProjectionEnvelope;
use Ultra\Projection\ProjectionPlan;
use Ultra\Projection\ProjectionPlanValidator;

final class TestProjection implements Projection
{
    public function name(): string
    {
        return 'test_projection';
    }

    public function version(): int
    {
        return 1;
    }

    public function apply(ProjectionEnvelope $event): void
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
    $plan = new ProjectionPlan(
        new TestProjection(),
        ['student.subscribed'],
        \Ultra\Projection\DeliveryMode::partitioned(PartitionStrategy::byDomainId('studentId')),
    );

    $validator = new ProjectionPlanValidator(new StaticEventTopologyProvider([
        'student.subscribed' => new EventTopology(['courseId', 'studentId']),
    ]));

    expect(fn () => $validator->validate($plan))->not->toThrow(\LogicException::class);
});

test('validator rejects partitioned plans when an event type does not expose the partition dimensions', function () {
    $plan = new ProjectionPlan(
        new TestProjection(),
        ['course.created'],
        \Ultra\Projection\DeliveryMode::partitioned(PartitionStrategy::byDomainId('studentId')),
    );

    $validator = new ProjectionPlanValidator(new StaticEventTopologyProvider([
        'course.created' => new EventTopology(['courseId']),
    ]));

    expect(fn () => $validator->validate($plan))
        ->toThrow(\LogicException::class, 'cannot partition event type "course.created" by [studentId]');
});
