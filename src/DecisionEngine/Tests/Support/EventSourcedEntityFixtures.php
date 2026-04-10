<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Support;

use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine\Attribute\EventSourcingHandler;
use Ultra\DecisionEngine\Attribute\MatchTag;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('compiler.course_created')]
final readonly class CompilerCourseCreated
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        public int $capacity,
    ) {}
}

#[Event('compiler.course_capacity_changed')]
final readonly class CompilerCourseCapacityChanged
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        public int $capacity,
    ) {}
}

#[Event('compiler.student_enrolled')]
final readonly class CompilerStudentEnrolled
{
    public function __construct(
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}

#[Event('compiler.student_subscribed')]
final readonly class CompilerStudentSubscribed
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}

#[Event('compiler.student_unsubscribed')]
final readonly class CompilerStudentUnsubscribed
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}

#[Event('compiler.pair_confirmed')]
final readonly class CompilerPairConfirmed
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
        public string $status,
    ) {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerSubscriptionState
{
    public int $capacity = 0;
    public bool $studentKnown = false;
    public int $subscriptionDelta = 0;

    /** @var list<string> */
    public array $applied = [];

    #[EventSourcingHandler]
    public function onCourseCreated(CompilerCourseCreated $event): void
    {
        $this->capacity = $event->capacity;
        $this->applied[] = 'created';
    }

    #[EventSourcingHandler]
    public function onCourseCapacityChanged(CompilerCourseCapacityChanged $event): void
    {
        $this->capacity = $event->capacity;
        $this->applied[] = 'capacity_changed';
    }

    #[EventSourcingHandler]
    public function onStudentEnrolled(CompilerStudentEnrolled $event): void
    {
        $this->studentKnown = true;
        $this->applied[] = 'student_enrolled';
    }

    #[EventSourcingHandler]
    #[MatchTag('course')]
    #[MatchTag('student')]
    public function onStudentSubscribed(CompilerStudentSubscribed $event): void
    {
        ++$this->subscriptionDelta;
        $this->applied[] = 'student_subscribed';
    }

    #[EventSourcingHandler]
    #[MatchTag('course')]
    #[MatchTag('student')]
    public function onStudentUnsubscribed(CompilerStudentUnsubscribed $event): void
    {
        --$this->subscriptionDelta;
        $this->applied[] = 'student_unsubscribed';
    }
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerExactSubscriptionState
{
    /** @var list<string> */
    public array $statuses = [];

    #[EventSourcingHandler]
    #[MatchTag('course', 'student')]
    public function onPairConfirmed(CompilerPairConfirmed $event): void
    {
        $this->statuses[] = $event->status;
    }
}

#[Event('compiler.no_overlap')]
final readonly class CompilerNoOverlapEvent
{
    public function __construct(
        #[DomainIdentifier('faculty')]
        public string $facultyId,
    ) {}
}

#[Event('compiler.ambiguous')]
final readonly class CompilerAmbiguousEvent
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
        #[DomainIdentifier('student')]
        public string $studentId,
    ) {}
}

#[Event('compiler.single')]
final readonly class CompilerSingleTagEvent
{
    public function __construct(
        #[DomainIdentifier('course')]
        public string $courseId,
    ) {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerNoOverlapState
{
    #[EventSourcingHandler]
    public function onEvent(CompilerNoOverlapEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerAmbiguousState
{
    #[EventSourcingHandler]
    public function onEvent(CompilerAmbiguousEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerUnnecessaryMatchTagState
{
    #[EventSourcingHandler]
    #[MatchTag('course')]
    public function onEvent(CompilerSingleTagEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course', 'student'])]
final class CompilerInvalidMatchTagState
{
    #[EventSourcingHandler]
    #[MatchTag('faculty')]
    public function onEvent(CompilerAmbiguousEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CompilerMissingHandlerAttributeState
{
    #[MatchTag('course')]
    public function onEvent(CompilerSingleTagEvent $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CompilerMissingTypeHintState
{
    #[EventSourcingHandler]
    public function onEvent(string $event): void {}
}

#[EventSourcedEntity(tags: ['course'])]
final class CompilerDuplicateHandlerState
{
    #[EventSourcingHandler]
    public function onFirst(CompilerSingleTagEvent $event): void {}

    #[EventSourcingHandler]
    public function onSecond(CompilerSingleTagEvent $event): void {}
}

final class CompilerUnknownState {}
