<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\State;

use App\Booking\EventSourced\Event\BookingCancelled;
use App\Booking\EventSourced\Event\BookingRescheduled;
use App\Booking\EventSourced\Event\RoomBooked;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;
use Ultra\EventSourcedState\Attribute\MatchTag;

/**
 * Consistency boundary for the pooled quota: every booking charged to one company in one month.
 *
 * A reschedule is loaded both by the month it moves to and, through the alias, by the month it leaves.
 */
#[EventSourcedEntity(tags: ['companyMonth'], aliases: ['previousCompanyMonth' => 'companyMonth'])]
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

    /**
     * The state does not know which month it is: a charge it already holds for a booking moved to another month
     * leaves, anything else is the month the booking moves to.
     */
    #[EventSourcingHandler]
    #[MatchTag('companyMonth')]
    #[MatchTag('previousCompanyMonth')]
    public function onRescheduled(BookingRescheduled $event): void
    {
        if (isset($this->charges[$event->bookingId]) && $event->companyMonthId !== $event->previousCompanyMonthId) {
            unset($this->charges[$event->bookingId]);

            return;
        }

        $this->charges[$event->bookingId] = self::minutes($event->start, $event->end);
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
