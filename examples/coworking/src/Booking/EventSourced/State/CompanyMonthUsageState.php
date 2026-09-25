<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\State;

use App\Booking\EventSourced\Event\BookingCancelled;
use App\Booking\EventSourced\Event\BookingRescheduledFrom;
use App\Booking\EventSourced\Event\BookingRescheduledTo;
use App\Booking\EventSourced\Event\RoomBooked;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

/**
 * Consistency boundary for the pooled quota: every booking charged to one company in one month.
 */
#[EventSourcedEntity(tags: ['companyMonth'])]
final class CompanyMonthUsageState
{
    /** @var array<string, int> minutes charged per booking id */
    private array $charges = [];

    public function usedMinutes(?string $ignoringBookingId = null): int
    {
        $charges = $this->charges;
        unset($charges[$ignoringBookingId ?? '']);

        return array_sum($charges);
    }

    #[EventSourcingHandler]
    public function onBooked(RoomBooked $event): void
    {
        $this->charges[$event->bookingId] = self::minutes($event->start, $event->end);
    }

    #[EventSourcingHandler]
    public function onRescheduledTo(BookingRescheduledTo $event): void
    {
        $this->charges[$event->bookingId] = self::minutes($event->start, $event->end);
    }

    #[EventSourcingHandler]
    public function onRescheduledFrom(BookingRescheduledFrom $event): void
    {
        unset($this->charges[$event->bookingId]);
    }

    #[EventSourcingHandler]
    public function onCancelled(BookingCancelled $event): void
    {
        if ($event->refunded) {
            unset($this->charges[$event->bookingId]);
        }
    }

    private static function minutes(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
    }
}
