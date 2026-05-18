<?php

declare(strict_types=1);

namespace App\Faculty\Write;

use App\Faculty\Event\StudentUnsubscribedFromCourse;
use App\Faculty\State\CourseState;
use App\Faculty\State\StudentState;
use App\Faculty\Support\FacultyIds;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\MessageHandling\Attribute\SelfHandler;

final readonly class UnsubscribeStudentFromCourseCommand
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {}

    /**
     * @return list<Violation>
     */
    public function check(CourseState $course, StudentState $student): array
    {
        return array_values(array_filter([
            !$course->exists()
                ? new Violation('course.not_found', 'Course does not exist.')
                : null,
            !$student->isEnrolled()
                ? new Violation('student.not_enrolled', 'Student must be enrolled in the faculty before unsubscribing from a course.')
                : null,
            !$student->isSubscribedTo($this->courseId)
                ? new Violation('student.not_subscribed', 'Student is not subscribed to this course.')
                : null,
        ]));
    }

    /**
     * @return list<StudentUnsubscribedFromCourse>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseState $course, StudentState $student): array
    {
        $violations = $this->check($course, $student);
        if ([] !== $violations) {
            throw new InvariantViolation($violations);
        }

        return [
            new StudentUnsubscribedFromCourse(FacultyIds::DEFAULT, $this->courseId, $this->studentId),
        ];
    }
}
