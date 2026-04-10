<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Ultra\Projection\Attribute\PartitionInitializer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\ProjectionPlan;
use Ultra\Projection\StoredEvent;

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
    public function onStudentSubscribed(StoredEvent $event): void {}
}

test('projection plans can invoke projection and partition initializers', function () {
    $projection = new TestProjection();
    $plan = new ProjectionPlan($projection);

    expect($plan->hasProjectionInitializer())->toBeTrue()
        ->and($plan->hasPartitionInitializer())->toBeTrue()
    ;

    $plan->initializeProjection();
    $plan->initializePartition('student-1');

    expect($projection->projectionInitializations)->toBe(1)
        ->and($projection->initializedPartitions)->toBe(['student-1'])
    ;
});
