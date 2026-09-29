<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests;

use Ultra\Events\Attribute\DomainIdentifier as DomainIdentifierAttribute;
use Ultra\Events\Attribute\Event;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event as StoredEvent;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StatefulMessageHandling\Tests\Support\StatefulMessageHandlingFunctionalTestCase;
use Ultra\StatefulMessageHandling\Tests\Support\StatefulMessageHandlingTestContainer;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\Testing\UltraLight;

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

final readonly class BookAliasDesk
{
    public function __construct(
        public string $dayId,
        public string $desk,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(AliasDayDesksState $day): AliasDeskBooked
    {
        if (isset($day->desks[$this->desk])) {
            throw new InvariantViolation([new Violation('desk.taken', sprintf('Desk %s is taken on %s.', $this->desk, $this->dayId))]);
        }

        return new AliasDeskBooked($this->dayId, $this->desk);
    }
}

final readonly class MoveAliasDesk
{
    public function __construct(
        public string $previousDayId,
        public string $dayId,
        public string $desk,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(AliasDayDesksState $day): AliasDeskMoved
    {
        if (isset($day->desks[$this->desk])) {
            throw new InvariantViolation([new Violation('desk.taken', sprintf('Desk %s is taken on %s.', $this->desk, $this->dayId))]);
        }

        return new AliasDeskMoved($this->previousDayId, $this->dayId, $this->desk);
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

/**
 * @internal
 *
 * @coversNothing
 */
final class EventSourcedAliasDecisionTest extends StatefulMessageHandlingFunctionalTestCase
{
    public function test_a_decision_sees_events_matched_through_the_alias_of_its_state_tag(): void
    {
        $container = $this->ultraLight()->boot();
        $mediator = $container->mediator();

        $mediator->send(new BookAliasDesk('monday', 'desk-1'));
        $mediator->send(new MoveAliasDesk('monday', 'tuesday', 'desk-1'));

        self::assertEquals(new AliasDeskBooked('monday', 'desk-1'), $mediator->send(new BookAliasDesk('monday', 'desk-1')));

        try {
            $mediator->send(new BookAliasDesk('tuesday', 'desk-1'));
            self::fail('The moved desk should be taken on the day it moved to.');
        } catch (InvariantViolation $violation) {
            self::assertEquals([new Violation('desk.taken', 'Desk desk-1 is taken on tuesday.')], $violation->violations);
        }
    }

    public function test_a_concurrent_event_tagged_with_the_alias_fails_the_append(): void
    {
        $container = $this->ultraLight()->boot();

        try {
            $container->mediator()->send(new BookAliasDeskWhileAnotherMovesAway('monday', 'desk-1'));
            self::fail('The append should have failed: an event of the loaded day was appended through its alias tag.');
        } catch (ConcurrencyException) {
        }

        self::assertCount(0, $container->eventStore()->load(
            StreamQuery::forStream(new DomainIdentifier('day', 'monday'))->ofTypes('alias_decision.desk_booked'),
        )->events);
    }

    /**
     * @return UltraLight<StatefulMessageHandlingTestContainer>
     */
    private function ultraLight(): UltraLight
    {
        return $this->decisionUltraLight([AliasDeskBooked::class, AliasDeskMoved::class])
            ->withClass(AliasDayDesksState::class, BookAliasDesk::class, MoveAliasDesk::class, BookAliasDeskWhileAnotherMovesAway::class)
        ;
    }
}
