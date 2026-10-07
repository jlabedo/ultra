<?php

declare(strict_types=1);

namespace App\Faculty\Write;

use App\Faculty\Event\StudentSubscribedToCourse;
use App\Faculty\State\CourseState;
use App\Faculty\State\StudentState;
use App\Faculty\Support\FacultyIds;
use App\Faculty\Support\SubscriptionPolicy;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Violation;

final readonly class SubscribeStudentToCourseCommand
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
            !$course->exists() ? new Violation('course.not_found', 'Course does not exist.') : null,
            !$student->isEnrolled()
                ? new Violation(
                    'student.not_enrolled',
                    'Student must be enrolled in the faculty before subscribing to a course.',
                )
                : null,
            $course->isFull() ? new Violation('course.full', 'Course has reached its maximum capacity.') : null,
            $student->isSubscribedTo($this->courseId)
                ? new Violation('student.already_subscribed', 'Student is already subscribed to this course.')
                : null,
            $student->subscriptionCount() >= SubscriptionPolicy::MAX_COURSES_PER_STUDENT
                ? new Violation('student.subscription_limit_reached', sprintf(
                    'A student can subscribe to at most %d courses.',
                    SubscriptionPolicy::MAX_COURSES_PER_STUDENT,
                ))
                : null,
        ]));
    }

    /**
     * @return list<StudentSubscribedToCourse>
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
            new StudentSubscribedToCourse(FacultyIds::DEFAULT, $this->courseId, $this->studentId),
        ];
    }
}
