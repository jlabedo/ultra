<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\Command;

use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\DecisionEngine\Exception\InvariantViolation;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\State\CourseCapacityState;
use Ultra\DecisionEngine\Tests\Example\State\StudentSubscriptionState;
use Ultra\DecisionEngine\Violation;
use Ultra\MessageHandling\Attribute\SelfHandler;

readonly class SubscribeStudentToCourseCommand
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {}

    /**
     * @return list<Violation>
     */
    public function check(
        CourseCapacityState $course,
        StudentSubscriptionState $student,
    ): array {
        return array_values(array_filter([
            ! $course->exists()
                ? new Violation('course.not_found', 'Course does not exist')
                : null,
            $course->isFull()
                ? new Violation('course.full', 'Course has reached maximum capacity')
                : null,
            $student->isSubscribedTo($this->courseId)
                ? new Violation('student.already_subscribed', 'Student is already subscribed')
                : null,
        ]));
    }

    /**
     * @return list<StudentSubscribed>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        CourseCapacityState $course,
        StudentSubscriptionState $student,
    ): array {
        $violations = $this->check($course, $student);

        if ([] !== $violations) {
            throw new InvariantViolation($violations);
        }

        return [
            new StudentSubscribed($this->courseId, $this->studentId),
        ];
    }
}
