<?php

declare(strict_types=1);

namespace App\Faculty\State;

use App\Faculty\Event\CourseCapacityChanged;
use App\Faculty\Event\CourseCreated;
use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\Event\StudentUnsubscribedFromCourse;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['faculty'])]
final class FacultyCourseAvailabilityState
{
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

    #[EventSourcingHandler]
    public function onCourseCreated(CourseCreated $event): void
    {
        $this->facultyId = $event->facultyId;
        $this->courses[$event->courseId] = [
            'capacity' => $event->capacity,
            'students' => 0,
        ];
    }

    #[EventSourcingHandler]
    public function onCourseCapacityChanged(CourseCapacityChanged $event): void
    {
        if (!isset($this->courses[$event->courseId])) {
            return;
        }

        $this->courses[$event->courseId]['capacity'] = $event->capacity;
    }

    #[EventSourcingHandler]
    public function onStudentSubscribedToCourse(StudentSubscribedToCourse $event): void
    {
        if (!isset($this->courses[$event->courseId])) {
            return;
        }

        ++$this->courses[$event->courseId]['students'];
    }

    #[EventSourcingHandler]
    public function onStudentUnsubscribedFromCourse(StudentUnsubscribedFromCourse $event): void
    {
        if (!isset($this->courses[$event->courseId])) {
            return;
        }

        $this->courses[$event->courseId]['students'] = max(0, $this->courses[$event->courseId]['students'] - 1);
    }
}
