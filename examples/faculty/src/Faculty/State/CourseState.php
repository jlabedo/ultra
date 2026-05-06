<?php

declare(strict_types=1);

namespace App\Faculty\State;

use App\Faculty\Event\CourseCapacityChanged;
use App\Faculty\Event\CourseCreated;
use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\Event\StudentUnsubscribedFromCourse;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['course'])]
final class CourseState
{
    private bool $exists = false;
    private string $courseId = '';

    private string $name = '';
    private int $capacity = 0;
    private int $subscribedStudents = 0;

    public function exists(): bool
    {
        return $this->exists;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function subscribedStudents(): int
    {
        return $this->subscribedStudents;
    }

    public function isFull(): bool
    {
        return $this->exists && $this->subscribedStudents >= $this->capacity;
    }

    #[EventSourcingHandler]
    public function onCourseCreated(CourseCreated $event): void
    {
        $this->exists = true;
        $this->courseId = $event->courseId;
        $this->name = $event->name;
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function onCourseCapacityChanged(CourseCapacityChanged $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function onStudentSubscribedToCourse(StudentSubscribedToCourse $event): void
    {
        ++$this->subscribedStudents;
    }

    #[EventSourcingHandler]
    public function onStudentUnsubscribedFromCourse(StudentUnsubscribedFromCourse $event): void
    {
        $this->subscribedStudents = max(0, $this->subscribedStudents - 1);
    }
}
