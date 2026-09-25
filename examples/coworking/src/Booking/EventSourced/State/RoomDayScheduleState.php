<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\State;

use App\Booking\Domain\Slot;
use App\Booking\EventSourced\Event\BookingCancelled;
use App\Booking\EventSourced\Event\BookingRescheduledFrom;
use App\Booking\EventSourced\Event\BookingRescheduledTo;
use App\Booking\EventSourced\Event\RoomBooked;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

/**
 * Consistency boundary for overlaps: every booking of one room on one day.
 */
#[EventSourcedEntity(tags: ['roomDay'])]
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

    #[EventSourcingHandler]
    public function onRescheduledTo(BookingRescheduledTo $event): void
    {
        $this->add($event->bookingId, $event->memberId, $event->companyId, $event->start, $event->end);
    }

    #[EventSourcingHandler]
    public function onRescheduledFrom(BookingRescheduledFrom $event): void
    {
        unset($this->bookings[$event->bookingId]);
    }

    #[EventSourcingHandler]
    public function onCancelled(BookingCancelled $event): void
    {
        unset($this->bookings[$event->bookingId]);
    }

    private function add(string $bookingId, string $memberId, string $companyId, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $this->bookings[$bookingId] = [
            'slot' => new Slot($start, $end),
            'memberId' => $memberId,
            'companyId' => $companyId,
        ];
    }
}
