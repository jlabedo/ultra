<?php

declare(strict_types=1);

namespace App\Faculty\Write\UnsubscribeStudentFromCourse;

use App\Faculty\Event\StudentUnsubscribedFromCourse;
use App\Faculty\State\CourseState;
use App\Faculty\State\StudentState;
use App\Faculty\Support\FacultyIds;
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Violation;
use Ultra\Mediator\Attribute\Handles;

final readonly class UnsubscribeStudentFromCourseCommand
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {}

    /**
     * @return list<Violation>
     */
    public function check(CourseState $course, ?StudentState $student): array
    {
        return array_values(array_filter([
            null === $student || ! $student->isEnrolled()
                ? new Violation('student.not_enrolled', 'Student must be enrolled in the faculty before unsubscribing from a course.')
                : null,
            null !== $student && ! $student->isSubscribedTo($this->courseId)
                ? new Violation('student.not_subscribed', 'Student is not subscribed to this course.')
                : null,
        ]));
    }

    /**
     * @return list<StudentUnsubscribedFromCourse>
     */
    #[Handles]
    #[DecisionHandler]
    public function decide(CourseState $course, ?StudentState $student): array
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
