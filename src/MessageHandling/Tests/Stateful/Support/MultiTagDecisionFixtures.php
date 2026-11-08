<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;
use Ultra\EventSourcedState\Tests\Example\Event\StudentSubscribed;
use Ultra\EventSourcedState\Tests\Example\Event\StudentTransferred;
use Ultra\EventSourcedState\Tests\Example\State\CourseStudentActivityState;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

#[EventSourcedEntity(tags: ['course', 'student'], aliases: ['previousCourse' => 'course'])]
final class CourseStudentPairState
{
    public int $subscriptions = 0;
    public int $departures = 0;

    #[EventSourcingHandler]
    #[MatchTag('course', 'student')]
    public function onSubscribed(StudentSubscribed $event): void
    {
        ++$this->subscriptions;
    }

    #[EventSourcingHandler]
    #[MatchTag('previousCourse', 'student')]
    public function onTransferred(StudentTransferred $event): void
    {
        ++$this->departures;
    }
}

final readonly class InspectMultiTagActivity
{
    public function __construct(public mixed $courseId, public mixed $studentId) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseStudentActivityState $activity, CourseStudentPairState $pair): array
    {
        return [$activity->maxCapacity, $activity->studentKnown, $activity->subscriptionDelta, $pair->subscriptions, $pair->departures];
    }
}

final readonly class InspectBoundMultiTagActivity
{
    public function __construct(public NestedCourseReference $reference, public string $memberId) {}

    public function selectedStudent(DependentMemberState $member): string
    {
        return $member->companyId;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        #[BindTo('reference.courseId', tag: 'course')]
        #[BindTo(method: 'selectedStudent', tag: 'student')]
        CourseStudentPairState $pair,
        DependentMemberState $member,
    ): int {
        return $pair->subscriptions;
    }
}

final readonly class InspectPartiallyBoundMultiTagActivity
{
    public function __construct(public NestedCourseReference $reference, private string|\Stringable $student) {}

    public function studentId(): string|\Stringable
    {
        return $this->student;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(#[BindTo('reference.courseId', tag: 'course')] CourseStudentPairState $pair): int
    {
        return $pair->subscriptions;
    }
}

final readonly class InspectDependentMultiTagActivity
{
    public function __construct(public string $courseId, public string $memberId) {}

    public function studentId(DependentMemberState $member): string
    {
        return $member->companyId;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseStudentPairState $pair, DependentMemberState $member): int
    {
        return $pair->subscriptions;
    }
}

final readonly class SubscribeWhileMultiTagHistoryChanges
{
    public function __construct(public string $courseId, public string $studentId, public Event $concurrent) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseStudentActivityState $activity, CourseStudentPairState $pair, EventStore $store): StudentSubscribed
    {
        $store->append([$this->concurrent]);

        return new StudentSubscribed($this->courseId, $this->studentId);
    }
}

final class MultiTagBindingWithoutTag
{
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(#[BindTo('courseId')] CourseStudentPairState $pair): void {}
}

final class MultiTagBindingUnknownTag
{
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(#[BindTo('courseId', tag: 'faculty')] CourseStudentPairState $pair): void {}
}

final class MultiTagBindingAliasTag
{
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(#[BindTo('courseId', tag: 'previousCourse')] CourseStudentPairState $pair): void {}
}

final class MultiTagBindingDuplicateTag
{
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        #[BindTo('courseId', tag: 'course')]
        #[BindTo(method: 'otherCourse', tag: 'course')]
        CourseStudentPairState $pair,
    ): void {}
}

final class MultiTagBindingMissingValue
{
    public string $courseId = 'course';

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseStudentPairState $pair): void {}
}

final class MultiTagBindingCycle
{
    public string $courseId = 'course';

    public function studentId(DependentMemberState $member): string
    {
        return $member->companyId;
    }

    public function memberId(CourseStudentPairState $pair): string
    {
        return 'member';
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseStudentPairState $pair, DependentMemberState $member): void {}
}

#[EventSourcedEntity(tags: [])]
final class UntaggedDecisionState {}

final class InspectUntaggedDecisionState
{
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(UntaggedDecisionState $state): void {}
}

final readonly class InspectSeparateMultiTagActivity
{
    public function __construct(public mixed $courseId, public mixed $studentId) {}
}

final class InspectSeparateMultiTagActivityHandler
{
    #[Handles]
    #[DecisionHandler]
    public function handle(InspectSeparateMultiTagActivity $command, CourseStudentActivityState $activity, CourseStudentPairState $pair): array
    {
        return [$activity->maxCapacity, $activity->studentKnown, $activity->subscriptionDelta, $pair->subscriptions, $pair->departures];
    }
}

final readonly class SubscribeDependentPairWhileMemberMoves
{
    public function __construct(public string $courseId, public string $memberId) {}

    public function studentId(DependentMemberState $member): string
    {
        return $member->companyId;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseStudentPairState $pair, DependentMemberState $member, EventStore $store): StudentSubscribed
    {
        $store->append([new Event('dependent_binding.member_joined', ['memberId' => $this->memberId, 'companyId' => 'grace'], ['member' => $this->memberId])]);

        return new StudentSubscribed($this->courseId, $member->companyId);
    }
}
