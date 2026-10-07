<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Write;

use App\Booking\Domain\BookingIds;
use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Slot;
use App\Booking\Domain\Violations;
use App\Booking\EventSourced\Event\BookingRescheduled;
use App\Booking\EventSourced\State\BookingState;
use App\Booking\EventSourced\State\CompanyMonthUsageState;
use App\Booking\EventSourced\State\CompanyState;
use App\Booking\EventSourced\State\RoomDayScheduleState;
use App\Booking\EventSourced\State\RoomState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\Time\ClockInterface;

/**
 * Moves a booking to another slot, possibly in another room, day or month.
 *
 * Only the target boundaries are loaded: releasing the previous slot has no invariant to protect.
 * The company and the target company-month depend on the company the booking was charged to.
 */
final readonly class RescheduleBooking
{
    public function __construct(
        public string $bookingId,
        public string $memberId,
        public string $roomId,
        public Slot $slot,
    ) {}

    public function roomDayId(): string
    {
        return BookingIds::roomDay($this->roomId, $this->slot->day());
    }

    public function companyId(BookingState $booking): string
    {
        return $booking->companyId;
    }

    public function companyMonthId(BookingState $booking): string
    {
        return BookingIds::companyMonth($booking->companyId, $this->slot->month());
    }

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        BookingState $booking,
        RoomState $room,
        CompanyState $company,
        RoomDayScheduleState $schedule,
        CompanyMonthUsageState $usage,
        ClockInterface $clock,
    ): BookingRescheduled {
        $now = $clock->now();
        Violations::throwIfAny([
            !$booking->exists() ? new Violation('booking.not_found', 'Booking does not exist.') : null,
            !$room->exists ? new Violation('room.not_found', 'Room does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $booking->memberId !== $this->memberId
                ? new Violation('booking.not_owner', 'Only the member who booked can reschedule.')
                : null,
            BookingState::CANCELLED === $booking->status
                ? new Violation('booking.already_cancelled', 'Booking is cancelled.')
                : null,
        ]);
        Violations::throwIfAny([
            !BookingPolicy::isRefundable($booking->slot, $now)
                ? new Violation(
                    'booking.too_late_to_reschedule',
                    'Bookings can only be moved until 24 hours before they start.',
                )
                : null,
            ...BookingPolicy::slotViolations($this->slot, $now),
            !$schedule->isFree($this->slot, ignoringBookingId: $this->bookingId)
                ? new Violation('slot.unavailable', 'Room is already booked for this slot.')
                : null,
            BookingPolicy::quotaExceeded(
                $company->monthlyMinutes,
                $usage->usedMinutes(ignoringBookingId: $this->bookingId),
                $this->slot->minutes(),
            ),
        ]);

        return new BookingRescheduled(
            $this->bookingId,
            $this->roomId,
            $booking->memberId,
            $booking->companyId,
            $this->slot->start,
            $this->slot->end,
            $booking->roomId,
            $booking->slot->start,
            $booking->slot->end,
        );
    }
}
