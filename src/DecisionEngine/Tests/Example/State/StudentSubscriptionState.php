<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\State;

use Ultra\DecisionEngine\ReducerTrait;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;
use Ultra\Events\Attribute\DomainIdentifier;

class StudentSubscriptionState
{
    use ReducerTrait;

    #[DomainIdentifier('student')]
    private string $studentId = '';

    /** @var array<string, true> */
    private array $subscribedCourseIds = [];

    public function isSubscribedTo(string $courseId): bool
    {
        return isset($this->subscribedCourseIds[$courseId]);
    }

    private function onStudentSubscribed(StudentSubscribed $event): void
    {
        $this->studentId = $event->studentId;
        $this->subscribedCourseIds[$event->courseId] = true;
    }

    private function onStudentUnsubscribed(StudentUnsubscribed $event): void
    {
        unset($this->subscribedCourseIds[$event->courseId]);
    }
}
