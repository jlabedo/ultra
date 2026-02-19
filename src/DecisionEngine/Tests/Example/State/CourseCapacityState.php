<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\State;

use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\DecisionEngine\ReducerTrait;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;

class CourseCapacityState
{
    use ReducerTrait;

    #[DomainIdentifier('course')]
    private string $courseId = '';

    private int $maxCapacity = 0;
    private int $subscribedCount = 0;

    public function isFull(): bool
    {
        return $this->subscribedCount >= $this->maxCapacity;
    }

    private function onCourseCreated(CourseCreated $event): void
    {
        $this->courseId = $event->courseId;
        $this->maxCapacity = $event->maxCapacity;
    }

    private function onStudentSubscribed(StudentSubscribed $event): void
    {
        $this->subscribedCount++;
    }

    private function onStudentUnsubscribed(StudentUnsubscribed $event): void
    {
        $this->subscribedCount--;
    }
}
