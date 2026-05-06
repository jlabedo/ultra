<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Example\State;

use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCapacityChanged;
use Ultra\DecisionEngine\Tests\Example\Event\CourseCreated;
use Ultra\DecisionEngine\Tests\Example\Event\StudentEnrolled;
use Ultra\DecisionEngine\Tests\Example\Event\StudentSubscribed;
use Ultra\DecisionEngine\Tests\Example\Event\StudentUnsubscribed;

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CourseStudentActivityState
{
    public int $maxCapacity = 0;
    public bool $studentKnown = false;
    public int $subscriptionDelta = 0;

    /** @var list<string> */
    public array $applied = [];

    #[EventSourcingHandler]
    public function onCourseCreated(CourseCreated $event): void
    {
        $this->maxCapacity = $event->maxCapacity;
        $this->applied[] = 'created';
    }

    #[EventSourcingHandler]
    public function onCourseCapacityChanged(CourseCapacityChanged $event): void
    {
        $this->maxCapacity = $event->maxCapacity;
        $this->applied[] = 'capacity_changed';
    }

    #[EventSourcingHandler]
    public function onStudentEnrolled(StudentEnrolled $event): void
    {
        $this->studentKnown = true;
        $this->applied[] = 'student_enrolled';
    }

    #[EventSourcingHandler]
    #[MatchTag('course')]
    #[MatchTag('student')]
    public function onStudentSubscribed(StudentSubscribed $event): void
    {
        ++$this->subscriptionDelta;
        $this->applied[] = 'student_subscribed';
    }

    #[EventSourcingHandler]
    #[MatchTag('course')]
    #[MatchTag('student')]
    public function onStudentUnsubscribed(StudentUnsubscribed $event): void
    {
        --$this->subscriptionDelta;
        $this->applied[] = 'student_unsubscribed';
    }
}
