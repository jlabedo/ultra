<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\State;

use App\Booking\Domain\Slot;
use App\Booking\EventSourced\Event\BookingCancelled;
use App\Booking\EventSourced\Event\BookingRescheduled;
use App\Booking\EventSourced\Event\RoomBooked;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;

/**
 * Consistency boundary for overlaps: every booking of one room on one day.
 *
 * A reschedule is loaded both by the room day it moves to and, through the alias, by the room day it leaves.
 */
#[EventSourcedEntity(tags: ['roomDay'], aliases: ['previousRoomDay' => 'roomDay'])]
final class RoomDayScheduleState
{
    /** @var array<string, array{slot: Slot, memberId: string, companyId: string}> */
    public private(set) array $bookings = [];

    public function isFree(Slot $slot, ?string $ignoringBookingId = null): bool
    {
        foreach ($this->bookings as $bookingId => $booking) {
            if ($bookingId !== $ignoringBookingId && $booking['slot']->overlaps($slot)) {
                return false;
            }
        }

        return true;
    }

    #[EventSourcingHandler]
    public function onBooked(RoomBooked $event): void
    {
        $this->add($event->bookingId, $event->memberId, $event->companyId, $event->start, $event->end);
    }

    /**
     * The state does not know which room day it is: a booking it already holds that moves to another room day
     * leaves, anything else is the room day the booking moves to (or a move within the same day).
     */
    #[EventSourcingHandler]
    #[MatchTag('roomDay')]
    #[MatchTag('previousRoomDay')]
    public function onRescheduled(BookingRescheduled $event): void
    {
        if (isset($this->bookings[$event->bookingId]) && $event->roomDayId !== $event->previousRoomDayId) {
            unset($this->bookings[$event->bookingId]);

            return;
        }

        $this->add($event->bookingId, $event->memberId, $event->companyId, $event->start, $event->end);
    }

    #[EventSourcingHandler]
    public function onCancelled(BookingCancelled $event): void
    {
        unset($this->bookings[$event->bookingId]);
    }

    private function add(
        string $bookingId,
        string $memberId,
        string $companyId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): void {
        $this->bookings[$bookingId] = [
            'slot' => new Slot($start, $end),
            'memberId' => $memberId,
            'companyId' => $companyId,
        ];
    }
}
