<?php

declare(strict_types=1);

namespace App\Faculty\State;

use App\Faculty\Event\CourseCapacityChanged;
use App\Faculty\Event\CourseCreated;
use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\Event\StudentUnsubscribedFromCourse;
use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\DecisionEngine\ReducerTrait;

final class CourseState
{
    use ReducerTrait;

    #[DomainIdentifier('course')]
    private string $courseId = '';

    private string $name = '';
    private int $capacity = 0;
    private int $subscribedStudents = 0;

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
        return $this->subscribedStudents >= $this->capacity;
    }

    private function onCourseCreated(CourseCreated $event): void
    {
        $this->courseId = $event->courseId;
        $this->name = $event->name;
        $this->capacity = $event->capacity;
    }

    private function onCourseCapacityChanged(CourseCapacityChanged $event): void
    {
        $this->capacity = $event->capacity;
    }

    private function onStudentSubscribedToCourse(StudentSubscribedToCourse $event): void
    {
        ++$this->subscribedStudents;
    }

    private function onStudentUnsubscribedFromCourse(StudentUnsubscribedFromCourse $event): void
    {
        $this->subscribedStudents = max(0, $this->subscribedStudents - 1);
    }
}
