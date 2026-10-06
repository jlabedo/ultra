<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;
use Ultra\EventSourcedState\Tests\Example\Event\CourseCreated;
use Ultra\EventSourcedState\Tests\Example\State\CourseCapacityState;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event as StoredEvent;
use Ultra\EventStore\EventStore;
use Ultra\Events\Attribute\DomainIdentifier as DomainIdentifierAttribute;
use Ultra\Events\Attribute\Event;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateStore;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Attribute\StateReader;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Violation;

final class DecisionEventBus implements EventBusInterface
{
    /** @var list<object> */
    public array $events = [];

    public function publish(object $event, array|MessageContext $context = []): void
    {
        $this->events[] = $event;
    }
}

#[Event('alias_decision.desk_booked')]
final readonly class AliasDeskBooked
{
    public function __construct(
        #[DomainIdentifierAttribute('day')]
        public string $dayId,
        public string $desk,
    ) {}
}

#[Event('alias_decision.desk_moved')]
final readonly class AliasDeskMoved
{
    public function __construct(
        #[DomainIdentifierAttribute('previousDay')]
        public string $previousDayId,
        #[DomainIdentifierAttribute('day')]
        public string $dayId,
        public string $desk,
    ) {}
}

/**
 * Desks taken on one day. A move is loaded by the day it leaves (through the alias) and by the day it lands on.
 */
#[EventSourcedEntity(tags: ['day'], aliases: ['previousDay' => 'day'])]
final class AliasDayDesksState
{
    /** @var array<string, true> */
    public array $desks = [];

    #[EventSourcingHandler]
    public function onBooked(AliasDeskBooked $event): void
    {
        $this->desks[$event->desk] = true;
    }

    #[EventSourcingHandler]
    #[MatchTag('day')]
    #[MatchTag('previousDay')]
    public function onMoved(AliasDeskMoved $event): void
    {
        if (isset($this->desks[$event->desk]) && $event->previousDayId !== $event->dayId) {
            unset($this->desks[$event->desk]);
        } else {
            $this->desks[$event->desk] = true;
        }
    }
}

/**
 * Books a desk while, between the load and the append, a booking of that desk on the same day is moved to another
 * day: the concurrent event only carries the day as its alias tag.
 */
final readonly class BookAliasDeskWhileAnotherMovesAway
{
    public function __construct(
        public string $dayId,
        public string $desk,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(AliasDayDesksState $day, EventStore $eventStore): AliasDeskBooked
    {
        $eventStore->append([new StoredEvent(
            eventType: 'alias_decision.desk_moved',
            payload: ['previousDayId' => $this->dayId, 'dayId' => 'elsewhere', 'desk' => 'other-desk'],
            domainIds: ['previousDay' => $this->dayId, 'day' => 'elsewhere'],
        )]);

        return new AliasDeskBooked($this->dayId, $this->desk);
    }
}

#[Event('dependent_binding.member_joined')]
final readonly class DependentMemberJoined
{
    public function __construct(
        #[DomainIdentifierAttribute('member')]
        public string $memberId,
        public string $companyId,
    ) {}
}

#[Event('dependent_binding.seat_taken')]
final readonly class DependentSeatTaken
{
    public function __construct(
        #[DomainIdentifierAttribute('companyMonth')]
        public string $companyMonthId,
        public string $memberId,
    ) {}
}

#[EventSourcedEntity(tags: ['member'])]
final class DependentMemberState
{
    public string $companyId = '';

    #[EventSourcingHandler]
    public function onJoined(DependentMemberJoined $event): void
    {
        $this->companyId = $event->companyId;
    }
}

#[EventSourcedEntity(tags: ['companyMonth'])]
final class DependentSeatsState
{
    public ?string $companyMonthId = null;

    /** @var list<string> */
    public array $memberIds = [];

    #[EventSourcingHandler]
    public function onSeatTaken(DependentSeatTaken $event): void
    {
        $this->companyMonthId = $event->companyMonthId;
        $this->memberIds[] = $event->memberId;
    }
}

final readonly class JoinDependentCompany
{
    public function __construct(
        public string $memberId,
        public string $companyId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(DependentMemberState $member): DependentMemberJoined
    {
        return new DependentMemberJoined($this->memberId, $this->companyId);
    }
}

/**
 * One seat per company and month; the company comes from the member.
 */
final readonly class TakeDependentSeat
{
    public function __construct(
        public string $memberId,
        public string $month,
    ) {}

    public function companyMonthId(DependentMemberState $member): string
    {
        return $member->companyId.'@'.$this->month;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        #[BindTo(method: 'companyMonthId')]
        DependentSeatsState $seats,
        DependentMemberState $member,
    ): DependentSeatTaken {
        if ([] !== $seats->memberIds) {
            throw new InvariantViolation([new Violation('seat.taken', sprintf('Seat of %s already taken.', $seats->companyMonthId))]);
        }

        return new DependentSeatTaken($this->companyMonthId($member), $this->memberId);
    }
}

final readonly class TakeDependentSeatForCompany
{
    public function __construct(
        public string $memberId,
        public string $companyId,
        public string $month,
    ) {}

    public function companyMonthId(): string
    {
        return $this->companyId.'@'.$this->month;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        #[BindTo(method: 'companyMonthId')]
        DependentSeatsState $seats,
    ): DependentSeatTaken {
        return new DependentSeatTaken($this->companyMonthId(), $this->memberId);
    }
}

/**
 * Moves the member to another company between the load and the append of its own decision.
 */
final readonly class TakeDependentSeatWhileMemberMoves
{
    public function __construct(
        public string $memberId,
        public string $month,
    ) {}

    public function companyMonthId(DependentMemberState $member): string
    {
        return $member->companyId.'@'.$this->month;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        DependentMemberState $member,
        #[BindTo(method: 'companyMonthId')]
        DependentSeatsState $seats,
        EventStore $eventStore,
    ): DependentSeatTaken {
        $eventStore->append([new StoredEvent(
            eventType: 'dependent_binding.member_joined',
            payload: ['memberId' => $this->memberId, 'companyId' => 'globex'],
            domainIds: ['member' => $this->memberId],
        )]);

        return new DependentSeatTaken($this->companyMonthId($member), $this->memberId);
    }
}

/**
 * Same decision as TakeDependentSeat, bound by convention: the companyMonth tag reads companyMonthId(), a method.
 */
final readonly class TakeDependentSeatByConvention
{
    public function __construct(
        public string $memberId,
        public string $month,
    ) {}

    public function companyMonthId(DependentMemberState $member): string
    {
        return $member->companyId.'@'.$this->month;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(DependentSeatsState $seats, DependentMemberState $member): DependentSeatTaken
    {
        if ([] !== $seats->memberIds) {
            throw new InvariantViolation([new Violation('seat.taken', sprintf('Seat of %s already taken.', $seats->companyMonthId))]);
        }

        return new DependentSeatTaken($this->companyMonthId($member), $this->memberId);
    }
}

final class DependentBindingConventionClashCommand
{
    public string $companyMonthId = 'acme@2026-09';

    public function companyMonthId(): string
    {
        return 'globex@2026-09';
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(DependentSeatsState $seats): array
    {
        return [];
    }
}

final readonly class DependentBindingConventionMissingCommand
{
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(DependentSeatsState $seats): array
    {
        return [];
    }
}

final readonly class DependentBindingUnknownParameterCommand
{
    public function __construct(public string $memberId) {}

    public function companyMonthId(DependentMemberState $someone): string
    {
        return $someone->companyId;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(DependentMemberState $member, #[BindTo(method: 'companyMonthId')] DependentSeatsState $seats): array
    {
        return [];
    }
}

final readonly class DependentBindingNonStateParameterCommand
{
    public function __construct(public string $memberId) {}

    public function companyMonthId(\DateTimeImmutable $clock): string
    {
        return $clock->format('Y-m');
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(#[BindTo(method: 'companyMonthId')] DependentSeatsState $seats, \DateTimeImmutable $clock): array
    {
        return [];
    }
}

final readonly class DependentBindingTypeMismatchCommand
{
    public function __construct(public string $memberId) {}

    public function companyMonthId(DependentSeatsState $member): string
    {
        return '';
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(DependentMemberState $member, #[BindTo(method: 'companyMonthId')] DependentSeatsState $seats): array
    {
        return [];
    }
}

final readonly class DependentBindingCycleCommand
{
    public function memberId(DependentSeatsState $seats): string
    {
        return '';
    }

    public function companyMonthId(DependentMemberState $member): string
    {
        return '';
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        #[BindTo(method: 'memberId')]
        DependentMemberState $member,
        #[BindTo(method: 'companyMonthId')]
        DependentSeatsState $seats,
    ): array {
        return [];
    }
}

final readonly class DependentBindingMissingMethodCommand
{
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(#[BindTo(method: 'companyMonthId')] DependentSeatsState $seats): array
    {
        return [];
    }
}

final readonly class DependentBindingBothTargetsCommand
{
    public function __construct(public string $companyMonthId) {}

    public function seatsId(): string
    {
        return $this->companyMonthId;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(#[BindTo('companyMonthId', method: 'seatsId')] DependentSeatsState $seats): array
    {
        return [];
    }
}

final readonly class DependentBindingNoTargetCommand
{
    public function __construct(public string $companyMonthId) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(#[BindTo] DependentSeatsState $seats): array
    {
        return [];
    }
}

#[State(table: 'dependent_members')]
final readonly class DependentMemberRow
{
    public function __construct(
        #[StateId(column: 'member_id')]
        public string $memberId,
        #[StateColumn('company_id')]
        public string $companyId,
    ) {}
}

#[State(table: 'dependent_companies')]
final readonly class DependentCompanyRow
{
    public function __construct(
        #[StateId(column: 'company_id')]
        public string $companyId,
        #[StateColumn('monthly_minutes')]
        public int $monthlyMinutes,
    ) {}
}

#[State(table: 'dependent_usages')]
final readonly class DependentUsageRow
{
    public function __construct(
        #[StateId(column: 'company_month_id')]
        public string $companyMonthId,
        #[StateColumn('used_minutes')]
        public int $usedMinutes,
    ) {}
}

/**
 * Three loading rounds: member, then its company, then the company's usage for the month.
 */
final readonly class ChargeDependentMinutes
{
    public function __construct(
        public string $memberId,
        public string $month,
        public int $minutes,
    ) {}

    public function companyId(DependentMemberRow $member): string
    {
        return $member->companyId;
    }

    public function companyMonthId(DependentCompanyRow $company, DependentMemberRow $member): string
    {
        return $member->companyId.'@'.$this->month;
    }

    /**
     * Bound by convention: each #[StateId] property name (companyId, companyMonthId) is a message method.
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?DependentUsageRow $usage,
        ?DependentCompanyRow $company,
        ?DependentMemberRow $member,
    ): DependentUsageRow {
        if (null === $member || null === $company) {
            throw new InvariantViolation(array_values(array_filter([
                null === $member ? new Violation('member.missing', 'Member is missing.') : null,
                null === $company ? new Violation('company.missing', 'Company is missing.') : null,
                null === $usage ? new Violation('usage.missing', 'Usage is missing.') : null,
            ])));
        }

        $used = ($usage?->usedMinutes ?? 0) + $this->minutes;
        if ($used > $company->monthlyMinutes) {
            throw new InvariantViolation([new Violation('quota.exceeded', sprintf('%s would use %d minutes.', $this->companyMonthId($company, $member), $used))]);
        }

        return new DependentUsageRow($this->companyMonthId($company, $member), $used);
    }
}

final readonly class GetDependentUsage
{
    public function __construct(
        public string $memberId,
        public string $month,
    ) {}

    public function companyMonthId(DependentMemberRow $member): string
    {
        return $member->companyId.'@'.$this->month;
    }

    #[SelfHandler]
    #[StateReader]
    public function read(
        ?DependentMemberRow $member,
        #[BindTo(method: 'companyMonthId')]
        ?DependentUsageRow $usage,
    ): ?int {
        return $usage?->usedMinutes;
    }
}

final readonly class DependentOnNullableSourceCommand
{
    public function __construct(public string $memberId) {}

    public function companyId(DependentMemberRow $member): string
    {
        return $member->companyId;
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?DependentMemberRow $member,
        #[BindTo(method: 'companyId')]
        DependentCompanyRow $company,
    ): array {
        return [];
    }
}

#[State(table: 'decision_orders')]
final readonly class DecisionOrderState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateColumn('status')]
        public string $status,
    ) {}

    public function withStatus(string $status): self
    {
        return new self($this->orderId, $status);
    }
}

final readonly class ShipDecisionOrderCommand
{
    public function __construct(
        public string $orderId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(DecisionOrderState $order): DecisionOrderState
    {
        return $order->withStatus('shipped');
    }
}

final readonly class ConfirmDecisionOrderCommand
{
    public function __construct(
        public string $orderId,
    ) {}
}

final readonly class ConfirmDecisionOrderHandler
{
    #[Handles]
    #[DecisionHandler]
    public function handle(
        ConfirmDecisionOrderCommand $command,
        DecisionOrderState $order,
    ): DecisionOrderState {
        return $order->withStatus('confirmed');
    }
}

final readonly class CreateDecisionOrderCommand
{
    public function __construct(
        public string $orderId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(?DecisionOrderState $order): DecisionOrderState
    {
        if (null !== $order) {
            throw new \LogicException('Order already exists.');
        }

        return new DecisionOrderState($this->orderId, 'pending');
    }
}

final readonly class GetDecisionOrderStatusQuery
{
    public function __construct(
        public string $orderId,
    ) {}

    #[SelfHandler]
    #[StateReader]
    public function handle(?DecisionOrderState $order): ?string
    {
        return $order?->status;
    }
}

final readonly class DecisionAcceptanceId
{
    public function __construct(
        public string $identityId,
        public string $termsId,
    ) {}
}

#[State(table: 'decision_acceptances')]
final readonly class DecisionAcceptanceState
{
    public function __construct(
        #[StateId(columns: ['identityId' => 'identity_id', 'termsId' => 'terms_id'])]
        public DecisionAcceptanceId $acceptanceId,
        public string $status,
    ) {}
}

#[State(table: 'decision_terms')]
final readonly class DecisionTermsState
{
    public function __construct(
        #[StateId(column: 'terms_id')]
        public string $termsId,
        #[StateColumn('status')]
        public string $status,
    ) {}
}

final readonly class AcceptDecisionTermsCommand
{
    public function __construct(
        public DecisionAcceptanceId $acceptanceId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function accept(
        #[BindTo('acceptanceId.termsId')]
        DecisionTermsState $terms,
        ?DecisionAcceptanceState $acceptance,
    ): DecisionAcceptanceState {
        if ('published' !== $terms->status) {
            throw new \LogicException('Terms are not published.');
        }
        if (null !== $acceptance) {
            throw new \LogicException('Acceptance already exists.');
        }

        return new DecisionAcceptanceState($this->acceptanceId, 'accepted');
    }
}

final readonly class GetDecisionTermsStatusQuery
{
    public function __construct(
        public DecisionAcceptanceId $acceptanceId,
    ) {}

    #[SelfHandler]
    #[StateReader]
    public function read(
        #[BindTo('acceptanceId.termsId')]
        DecisionTermsState $terms,
        ?DecisionAcceptanceState $acceptance,
    ): array {
        return [$terms->status, $acceptance?->status];
    }
}

final readonly class NestedCourseReference
{
    public function __construct(
        public string $courseId,
    ) {}
}

final readonly class CreateNestedCourseCommand
{
    public function __construct(
        public NestedCourseReference $reference,
        public string $name,
        public int $maxCapacity,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        #[BindTo('reference.courseId')]
        CourseCapacityState $course,
    ): array {
        if ($course->exists()) {
            throw new \InvalidArgumentException('Course already exists');
        }

        return [new CourseCreated($this->reference->courseId, $this->name, $this->maxCapacity)];
    }
}

final readonly class CreateCourseWithSeparateHandlerCommand
{
    public function __construct(
        public string $courseId,
        public string $name,
        public int $maxCapacity,
    ) {}
}

final readonly class CreateCourseWithSeparateHandler
{
    #[Handles]
    #[DecisionHandler]
    public function handle(
        CreateCourseWithSeparateHandlerCommand $command,
        CourseCapacityState $course,
    ): array {
        return [new CourseCreated($command->courseId, $command->name, $command->maxCapacity)];
    }
}

final class DecisionService
{
    public int $calls = 0;

    public function called(): void
    {
        ++$this->calls;
    }
}

final readonly class InspectDecisionCourse
{
    public function __construct(public string $courseId, public mixed $result, public ?\Throwable $failure = null) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseCapacityState $course, DecisionService $service): mixed
    {
        $service->called();
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->result;
    }
}

final readonly class InspectDecisionOrder
{
    public function __construct(public string $orderId, public mixed $result, public ?\Throwable $failure = null) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(?DecisionOrderState $order, DecisionService $service): mixed
    {
        $service->called();
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->result;
    }
}

final readonly class InspectMixedDecision
{
    public function __construct(public string $courseId, public string $orderId, public mixed $result, public ?\Throwable $failure = null) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(CourseCapacityState $course, ?DecisionOrderState $order, DecisionService $service): mixed
    {
        $service->called();
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->result;
    }
}
