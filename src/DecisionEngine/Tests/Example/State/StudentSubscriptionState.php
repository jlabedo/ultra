<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\State;

use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;

#[EventSourcedEntity(tags: ['student'])]
class StudentSubscriptionState
{
    private string $studentId = '';

    /** @var array<string, true> */
    private array $subscribedCourseIds = [];

    public function isSubscribedTo(string $courseId): bool
    {
        return isset($this->subscribedCourseIds[$courseId]);
    }

    #[EventSourcingHandler]
    public function onStudentSubscribed(StudentSubscribed $event): void
    {
        $this->studentId = $event->studentId;
        $this->subscribedCourseIds[$event->courseId] = true;
    }

    #[EventSourcingHandler]
    public function onStudentUnsubscribed(StudentUnsubscribed $event): void
    {
        unset($this->subscribedCourseIds[$event->courseId]);
    }
}
