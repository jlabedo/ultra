<?php

declare(strict_types=1);

namespace App\Faculty\State;

use App\Faculty\Event\CourseCapacityChanged;
use App\Faculty\Event\CourseCreated;
use App\Faculty\Event\StudentEnrolledInFaculty;
use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\Event\StudentUnsubscribedFromCourse;
use App\Faculty\Read\Model\CourseStatsView;
use App\Faculty\Read\Model\FacultyOverviewView;
use App\Faculty\Read\Model\StudentProfileView;
use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['faculty'])]
final class FacultyReadModelState
{
    /**
     * @var array<string, array{name: string, capacity: int, subscribedStudents: int}>
     */
    private array $courses = [];

    /**
     * @var array<string, array{firstName: string, lastName: string, subscribedCourseIds: array<string, true>}>
     */
    private array $students = [];

    public function course(string $courseId): ?CourseStatsView
    {
        $course = $this->courses[$courseId] ?? null;
        if (null === $course) {
            return null;
        }

        return new CourseStatsView(
            $courseId,
            $course['name'],
            $course['capacity'],
            $course['subscribedStudents'],
        );
    }

    public function overview(): FacultyOverviewView
    {
        return new FacultyOverviewView(
            $this->courses(),
            $this->students(),
        );
    }

    /**
     * @return list<CourseStatsView>
     */
    public function courses(): array
    {
        $courses = $this->courses;
        ksort($courses);

        $views = [];
        foreach ($courses as $courseId => $course) {
            $views[] = new CourseStatsView(
                $courseId,
                $course['name'],
                $course['capacity'],
                $course['subscribedStudents'],
            );
        }

        return $views;
    }

    public function student(string $studentId): ?StudentProfileView
    {
        $student = $this->students[$studentId] ?? null;
        if (null === $student) {
            return null;
        }

        $subscriptions = array_keys($student['subscribedCourseIds']);
        sort($subscriptions);

        return new StudentProfileView(
            $studentId,
            $student['firstName'],
            $student['lastName'],
            $subscriptions,
        );
    }

    /**
     * @return list<StudentProfileView>
     */
    public function students(): array
    {
        $students = $this->students;
        ksort($students);

        $views = [];
        foreach ($students as $studentId => $student) {
            $subscriptions = array_keys($student['subscribedCourseIds']);
            sort($subscriptions);

            $views[] = new StudentProfileView(
                $studentId,
                $student['firstName'],
                $student['lastName'],
                $subscriptions,
            );
        }

        return $views;
    }

    #[EventSourcingHandler]
    public function onCourseCreated(CourseCreated $event): void
    {
        $this->courses[$event->courseId] = [
            'name' => $event->name,
            'capacity' => $event->capacity,
            'subscribedStudents' => 0,
        ];
    }

    #[EventSourcingHandler]
    public function onCourseCapacityChanged(CourseCapacityChanged $event): void
    {
        if (! isset($this->courses[$event->courseId])) {
            return;
        }

        $this->courses[$event->courseId]['capacity'] = $event->capacity;
    }

    #[EventSourcingHandler]
    public function onStudentEnrolledInFaculty(StudentEnrolledInFaculty $event): void
    {
        $this->students[$event->studentId] = [
            'firstName' => $event->firstName,
            'lastName' => $event->lastName,
            'subscribedCourseIds' => $this->students[$event->studentId]['subscribedCourseIds'] ?? [],
        ];
    }

    #[EventSourcingHandler]
    public function onStudentSubscribedToCourse(StudentSubscribedToCourse $event): void
    {
        if (isset($this->courses[$event->courseId])) {
            ++$this->courses[$event->courseId]['subscribedStudents'];
        }

        if (! isset($this->students[$event->studentId])) {
            return;
        }

        $this->students[$event->studentId]['subscribedCourseIds'][$event->courseId] = true;
    }

    #[EventSourcingHandler]
    public function onStudentUnsubscribedFromCourse(StudentUnsubscribedFromCourse $event): void
    {
        if (isset($this->courses[$event->courseId])) {
            $this->courses[$event->courseId]['subscribedStudents'] = max(
                0,
                $this->courses[$event->courseId]['subscribedStudents'] - 1,
            );
        }

        if (! isset($this->students[$event->studentId])) {
            return;
        }

        unset($this->students[$event->studentId]['subscribedCourseIds'][$event->courseId]);
    }
}
