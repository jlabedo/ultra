<?php

declare(strict_types=1);

namespace App\Faculty\State;

use App\Faculty\Event\CourseCapacityChanged;
use App\Faculty\Event\CourseCreated;
use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\Event\StudentUnsubscribedFromCourse;
use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\DecisionEngine\ReducerTrait;

final class FacultyCourseAvailabilityState
{
    use ReducerTrait;

    #[DomainIdentifier('faculty')]
    private string $facultyId = '';

    /** @var array<string, array{capacity: int, students: int}> */
    private array $courses = [];

    public function allCoursesFullyBooked(): bool
    {
        if ([] === $this->courses) {
            return false;
        }

        foreach ($this->courses as $course) {
            if ($course['students'] < $course['capacity']) {
                return false;
            }
        }

        return true;
    }

    public function hasCourses(): bool
    {
        return [] !== $this->courses;
    }

    private function onCourseCreated(CourseCreated $event): void
    {
        $this->facultyId = $event->facultyId;
        $this->courses[$event->courseId] = [
            'capacity' => $event->capacity,
            'students' => 0,
        ];
    }

    private function onCourseCapacityChanged(CourseCapacityChanged $event): void
    {
        if (! isset($this->courses[$event->courseId])) {
            return;
        }

        $this->courses[$event->courseId]['capacity'] = $event->capacity;
    }

    private function onStudentSubscribedToCourse(StudentSubscribedToCourse $event): void
    {
        if (! isset($this->courses[$event->courseId])) {
            return;
        }

        ++$this->courses[$event->courseId]['students'];
    }

    private function onStudentUnsubscribedFromCourse(StudentUnsubscribedFromCourse $event): void
    {
        if (! isset($this->courses[$event->courseId])) {
            return;
        }

        $this->courses[$event->courseId]['students'] = max(0, $this->courses[$event->courseId]['students'] - 1);
    }
}
