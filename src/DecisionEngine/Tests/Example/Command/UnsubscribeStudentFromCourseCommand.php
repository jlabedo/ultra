<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Command;

use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Violation;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;
use Ultra\DecisionEngine\Tests\Example\State\StudentSubscriptionState;

readonly class UnsubscribeStudentFromCourseCommand
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {
    }

    /**
     * @return list<Violation>
     */
    public function check(
        CourseCapacityState $course,
        StudentSubscriptionState $student,
    ): array {
        return array_filter([
            !$student->isSubscribedTo($this->courseId)
                ? new Violation('student.not_subscribed', 'Student is not subscribed to this course')
                : null,
        ]);
    }

    /**
     * @return list<StudentUnsubscribed>
     */
    public function decide(
        CourseCapacityState $course,
        StudentSubscriptionState $student,
    ): array {
        $violations = $this->check($course, $student);

        if ($violations !== []) {
            throw new InvariantViolation($violations);
        }

        return [
            new StudentUnsubscribed($this->courseId, $this->studentId),
        ];
    }
}
