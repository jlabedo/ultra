<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

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

#[Event('compiler.room_day_booked')]
final readonly class CompilerRoomDayBookedEvent
{
    public function __construct(
        #[DomainIdentifier('roomDay')]
        public string $roomDayId,
    ) {}
}

#[Event('compiler.room_day_moved')]
final readonly class CompilerRoomDayMovedEvent
{
    public function __construct(
        #[DomainIdentifier('roomDay')]
        public string $roomDayId,
        #[DomainIdentifier('previousRoomDay')]
        public string $previousRoomDayId,
    ) {}
}

#[EventSourcedEntity(tags: ['roomDay'], aliases: ['previousRoomDay' => 'room'])]
final class CompilerAliasOfUnknownTagState
{
    #[EventSourcingHandler]
    public function onBooked(CompilerRoomDayBookedEvent $event): void {}
}

#[EventSourcedEntity(tags: ['roomDay', 'room'], aliases: ['room' => 'roomDay'])]
final class CompilerAliasShadowingATagState
{
    #[EventSourcingHandler]
    public function onBooked(CompilerRoomDayBookedEvent $event): void {}
}

#[EventSourcedEntity(tags: ['roomDay'], aliases: ['roomDay'])]
final class CompilerAliasListState
{
    #[EventSourcingHandler]
    public function onBooked(CompilerRoomDayBookedEvent $event): void {}
}

#[EventSourcedEntity(tags: ['roomDay'], aliases: ['previousRoomDay' => 'roomDay'])]
final class CompilerAmbiguousAliasState
{
    #[EventSourcingHandler]
    public function onMoved(CompilerRoomDayMovedEvent $event): void {}
}

#[EventSourcedEntity(tags: ['roomDay'], aliases: ['previousRoomDay' => 'roomDay'])]
final class CompilerAliasedRoomDayState
{
    #[EventSourcingHandler]
    public function onBooked(CompilerRoomDayBookedEvent $event): void {}

    #[EventSourcingHandler]
    #[MatchTag('roomDay')]
    #[MatchTag('previousRoomDay')]
    public function onMoved(CompilerRoomDayMovedEvent $event): void {}
}

#[EventSourcedEntity(tags: ['roomDay'], aliases: ['previousRoomDay' => 'roomDay'])]
final class CompilerSameDayMoveState
{
    #[EventSourcingHandler]
    #[MatchTag('previousRoomDay', 'roomDay')]
    public function onMoved(CompilerRoomDayMovedEvent $event): void {}
}
