<?php

declare(strict_types=1);

namespace App\Faculty\Read;

use App\Faculty\Event\CourseCapacityChanged;
use App\Faculty\Event\CourseCreated;
use App\Faculty\Event\StudentEnrolledInFaculty;
use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\Event\StudentUnsubscribedFromCourse;
use App\Faculty\Read\Model\CourseStatsView;
use App\Faculty\Read\Model\FacultyOverviewView;
use App\Faculty\Read\Model\StudentProfileView;
use App\Faculty\Support\EventTypes;
use App\Faculty\Support\FacultyHistory;

final readonly class FacultyReadModelBuilder
{
    public function __construct(
        private FacultyHistory $history,
    ) {}

    public function course(string $courseId): ?CourseStatsView
    {
        return $this->buildState()['courses'][$courseId] ?? null;
    }

    public function overview(): FacultyOverviewView
    {
        $state = $this->buildState();

        return new FacultyOverviewView(
            array_values($state['courses']),
            array_values($state['students']),
        );
    }

    /**
     * @return list<CourseStatsView>
     */
    public function courses(): array
    {
        return array_values($this->buildState()['courses']);
    }

    public function student(string $studentId): ?StudentProfileView
    {
        return $this->buildState()['students'][$studentId] ?? null;
    }

    /**
     * @return list<StudentProfileView>
     */
    public function students(): array
    {
        return array_values($this->buildState()['students']);
    }

    /**
     * @return array{courses: array<string, CourseStatsView>, students: array<string, StudentProfileView>}
     */
    private function buildState(): array
    {
        $courses = [];
        $students = [];

        foreach ($this->history->loadFacultyEvents(
            EventTypes::COURSE_CREATED,
            EventTypes::COURSE_CAPACITY_CHANGED,
            EventTypes::STUDENT_ENROLLED_IN_FACULTY,
            EventTypes::STUDENT_SUBSCRIBED_TO_COURSE,
            EventTypes::STUDENT_UNSUBSCRIBED_FROM_COURSE,
        ) as $event) {
            match (true) {
                $event instanceof CourseCreated => $courses[$event->courseId] = new CourseStatsView(
                    $event->courseId,
                    $event->name,
                    $event->capacity,
                    0,
                ),
                $event instanceof CourseCapacityChanged => isset($courses[$event->courseId])
                    ? $courses[$event->courseId] = new CourseStatsView(
                        $courses[$event->courseId]->courseId,
                        $courses[$event->courseId]->name,
                        $event->capacity,
                        $courses[$event->courseId]->subscribedStudents,
                    )
                    : null,
                $event instanceof StudentEnrolledInFaculty => $students[$event->studentId] = new StudentProfileView(
                    $event->studentId,
                    $event->firstName,
                    $event->lastName,
                    $students[$event->studentId]->subscribedCourseIds ?? [],
                ),
                $event instanceof StudentSubscribedToCourse => $this->applyStudentSubscribed($courses, $students, $event),
                $event instanceof StudentUnsubscribedFromCourse => $this->applyStudentUnsubscribed($courses, $students, $event),
                default => null,
            };
        }

        ksort($courses);
        ksort($students);

        return [
            'courses' => $courses,
            'students' => $students,
        ];
    }

    /**
     * @param array<string, CourseStatsView>    $courses
     * @param array<string, StudentProfileView> $students
     */
    private function applyStudentSubscribed(
        array &$courses,
        array &$students,
        StudentSubscribedToCourse $event,
    ): void {
        if (isset($courses[$event->courseId])) {
            $course = $courses[$event->courseId];
            $courses[$event->courseId] = new CourseStatsView(
                $course->courseId,
                $course->name,
                $course->capacity,
                $course->subscribedStudents + 1,
            );
        }

        if (! isset($students[$event->studentId])) {
            return;
        }

        $subscriptions = $students[$event->studentId]->subscribedCourseIds;
        if (! in_array($event->courseId, $subscriptions, true)) {
            $subscriptions[] = $event->courseId;
            sort($subscriptions);
        }

        $student = $students[$event->studentId];
        $students[$event->studentId] = new StudentProfileView(
            $student->studentId,
            $student->firstName,
            $student->lastName,
            $subscriptions,
        );
    }

    /**
     * @param array<string, CourseStatsView>    $courses
     * @param array<string, StudentProfileView> $students
     */
    private function applyStudentUnsubscribed(
        array &$courses,
        array &$students,
        StudentUnsubscribedFromCourse $event,
    ): void {
        if (isset($courses[$event->courseId])) {
            $course = $courses[$event->courseId];
            $courses[$event->courseId] = new CourseStatsView(
                $course->courseId,
                $course->name,
                $course->capacity,
                max(0, $course->subscribedStudents - 1),
            );
        }

        if (! isset($students[$event->studentId])) {
            return;
        }

        $subscriptions = array_values(array_filter(
            $students[$event->studentId]->subscribedCourseIds,
            static fn (string $courseId): bool => $courseId !== $event->courseId,
        ));

        $student = $students[$event->studentId];
        $students[$event->studentId] = new StudentProfileView(
            $student->studentId,
            $student->firstName,
            $student->lastName,
            $subscriptions,
        );
    }
}
