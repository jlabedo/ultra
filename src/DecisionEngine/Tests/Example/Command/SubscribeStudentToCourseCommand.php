<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Command;

use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Violation;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;
use Ultra\DecisionEngine\Tests\Example\State\StudentSubscriptionState;

readonly class SubscribeStudentToCourseCommand
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
        ?StudentSubscriptionState $student,
    ): array {
        return array_filter([
            $course->isFull()
                ? new Violation('course.full', 'Course has reached maximum capacity')
                : null,
            $student !== null && $student->isSubscribedTo($this->courseId)
                ? new Violation('student.already_subscribed', 'Student is already subscribed')
                : null,
        ]);
    }

    /**
     * @return list<StudentSubscribed>
     */
    public function decide(
        CourseCapacityState $course,
        ?StudentSubscriptionState $student,
    ): array {
        $violations = $this->check($course, $student);

        if ($violations !== []) {
            throw new InvariantViolation($violations);
        }

        return [
            new StudentSubscribed($this->courseId, $this->studentId),
        ];
    }
}
