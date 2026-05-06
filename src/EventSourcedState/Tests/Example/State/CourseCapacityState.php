<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Tests\Example\State;

use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventSourcedState\Tests\Example\Event\StudentSubscribed;
use Ultra\EventSourcedState\Tests\Example\Event\StudentUnsubscribed;

#[EventSourcedEntity(tags: ['course'])]
class CourseCapacityState
{
    private bool $exists = false;
    private string $courseId = '';

    private int $maxCapacity = 0;
    private int $subscribedCount = 0;

    public function exists(): bool
    {
        return $this->exists;
    }

    public function isFull(): bool
    {
        return $this->exists && $this->subscribedCount >= $this->maxCapacity;
    }

    #[EventSourcingHandler]
    public function onCourseCreated(CourseCreated $event): void
    {
        $this->exists = true;
        $this->courseId = $event->courseId;
        $this->maxCapacity = $event->maxCapacity;
    }

    #[EventSourcingHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void
    {
        ++$this->subscribedCount;
    }

    #[EventSourcingHandler]
    public function onStudentUnsubscribed(StudentUnsubscribed $event): void
    {
        --$this->subscribedCount;
    }
}
