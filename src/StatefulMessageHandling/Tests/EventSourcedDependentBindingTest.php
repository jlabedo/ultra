<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use Ultra\Events\Attribute\DomainIdentifier as DomainIdentifierAttribute;
use Ultra\Events\Attribute\Event;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event as StoredEvent;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\DecisionMode;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Tests\Support\StatefulMessageHandlingFunctionalTestCase;
use Ultra\StatefulMessageHandling\Tests\Support\StatefulMessageHandlingTestContainer;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\Testing\UltraLight;

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

/**
 * @internal
 *
 * @coversNothing
 */
final class EventSourcedDependentBindingTest extends StatefulMessageHandlingFunctionalTestCase
{
    public function test_a_dependent_state_is_loaded_from_the_id_computed_from_its_source_state(): void
    {
        $container = $this->ultraLight()->boot();
        $container->mediator()->send(new JoinDependentCompany('ada', 'acme'));

        $events = $container->mediator()->send(new TakeDependentSeat('ada', '2026-09'));

        self::assertEquals(new DependentSeatTaken('acme@2026-09', 'ada'), $events);
        self::assertCount(1, $container->eventStore()->load(self::seatsQuery('acme@2026-09'))->events);

        $container->mediator()->send(new JoinDependentCompany('grace', 'acme'));

        try {
            $container->mediator()->send(new TakeDependentSeat('grace', '2026-09'));
            self::fail('The dependent state should have been loaded with the seat already taken.');
        } catch (InvariantViolation $violation) {
            self::assertEquals([new Violation('seat.taken', 'Seat of acme@2026-09 already taken.')], $violation->violations);
        }
    }

    public function test_check_mode_runs_with_dependent_states(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();
        $mediator->send(new JoinDependentCompany('ada', 'acme'));
        $mediator->send(new JoinDependentCompany('grace', 'acme'));
        $check = [DecisionMode::CONTEXT_KEY => DecisionMode::CHECK];

        self::assertSame([], $mediator->send(new TakeDependentSeat('ada', '2026-09'), $check));
        self::assertCount(0, $container->eventStore()->load(self::seatsQuery('acme@2026-09'))->events);

        $mediator->send(new TakeDependentSeat('ada', '2026-09'));

        self::assertEquals(
            [new Violation('seat.taken', 'Seat of acme@2026-09 already taken.')],
            $mediator->send(new TakeDependentSeat('grace', '2026-09'), $check),
        );
    }

    public function test_a_parameterless_method_binds_a_derived_id(): void
    {
        $container = $this->ultraLight()->boot();

        $events = $container->mediator()->send(new TakeDependentSeatForCompany('ada', 'acme', '2026-09'));

        self::assertEquals(new DependentSeatTaken('acme@2026-09', 'ada'), $events);
        self::assertCount(1, $container->eventStore()->load(self::seatsQuery('acme@2026-09'))->events);
    }

    public function test_a_concurrent_change_of_a_source_stream_fails_the_append(): void
    {
        $container = $this->ultraLight()->boot();
        $container->mediator()->send(new JoinDependentCompany('ada', 'acme'));

        try {
            $container->mediator()->send(new TakeDependentSeatWhileMemberMoves('ada', '2026-09'));
            self::fail('The append should have failed: the member changed company after being loaded.');
        } catch (ConcurrencyException) {
        }

        self::assertCount(0, $container->eventStore()->load(self::seatsQuery('acme@2026-09'))->events);
    }

    public function test_the_conventional_id_can_come_from_a_message_method(): void
    {
        $container = $this->ultraLight()->boot();
        $container->mediator()->send(new JoinDependentCompany('ada', 'acme'));
        $container->mediator()->send(new JoinDependentCompany('grace', 'acme'));

        self::assertEquals(new DependentSeatTaken('acme@2026-09', 'ada'), $container->mediator()->send(new TakeDependentSeatByConvention('ada', '2026-09')));

        $this->expectException(InvariantViolation::class);
        $container->mediator()->send(new TakeDependentSeatByConvention('grace', '2026-09'));
    }

    public function test_a_conventional_name_matching_both_a_property_and_a_method_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has both a property and a method named "companyMonthId"');

        $this->ultraLight(DependentBindingConventionClashCommand::class)->boot();
    }

    public function test_a_conventional_name_matching_neither_a_property_nor_a_method_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no property path or method "companyMonthId"');

        $this->ultraLight(DependentBindingConventionMissingCommand::class)->boot();
    }

    public function test_a_method_parameter_must_match_a_state_parameter_by_name(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('parameter "$someone" of '.DependentBindingUnknownParameterCommand::class.'::companyMonthId() does not match any state parameter');

        $this->ultraLight(DependentBindingUnknownParameterCommand::class)->boot();
    }

    public function test_a_method_parameter_must_be_a_state_parameter(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('parameter "$clock" of '.DependentBindingNonStateParameterCommand::class.'::companyMonthId() does not match any state parameter');

        $this->ultraLight(DependentBindingNonStateParameterCommand::class)
            ->withService(new \DateTimeImmutable(), \DateTimeImmutable::class)
            ->boot()
        ;
    }

    public function test_a_method_parameter_must_have_the_state_type(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('parameter "$member" of '.DependentBindingTypeMismatchCommand::class.'::companyMonthId() must be typed '.DependentMemberState::class);

        $this->ultraLight(DependentBindingTypeMismatchCommand::class)->boot();
    }

    public function test_cyclic_dependencies_are_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cyclic method binding dependency: member -> seats -> member');

        $this->ultraLight(DependentBindingCycleCommand::class)->boot();
    }

    public function test_the_bound_method_must_exist(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('message '.DependentBindingMissingMethodCommand::class.' has no method "companyMonthId"');

        $this->ultraLight(DependentBindingMissingMethodCommand::class)->boot();
    }

    public function test_bind_to_requires_either_a_property_or_a_method(): void
    {
        foreach ([DependentBindingBothTargetsCommand::class, DependentBindingNoTargetCommand::class] as $command) {
            try {
                $this->ultraLight($command)->boot();
                self::fail(sprintf('%s should not compile.', $command));
            } catch (\LogicException $exception) {
                self::assertStringContainsString('#[BindTo] requires exactly one of a property path or a method.', $exception->getMessage());
            }
        }
    }

    /**
     * @param class-string ...$extraClasses
     *
     * @return UltraLight<StatefulMessageHandlingTestContainer>
     */
    private function ultraLight(string ...$extraClasses): UltraLight
    {
        $classes = [] === $extraClasses
            ? [
                JoinDependentCompany::class,
                TakeDependentSeat::class,
                TakeDependentSeatForCompany::class,
                TakeDependentSeatWhileMemberMoves::class,
                TakeDependentSeatByConvention::class,
            ]
            : $extraClasses;

        return $this->decisionUltraLight([DependentMemberJoined::class, DependentSeatTaken::class])
            ->withClass(DependentMemberState::class, DependentSeatsState::class, ...$classes)
        ;
    }

    private static function seatsQuery(string $companyMonthId): StreamQuery
    {
        return StreamQuery::forStream(new DomainIdentifier('companyMonth', $companyMonthId));
    }
}
