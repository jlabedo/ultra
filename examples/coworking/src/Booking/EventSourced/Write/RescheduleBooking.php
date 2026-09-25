<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Write;

use App\Booking\Domain\BookingIds;
use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Slot;
use App\Booking\Domain\Violations;
use App\Booking\EventSourced\Event\BookingRescheduledFrom;
use App\Booking\EventSourced\Event\BookingRescheduledTo;
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
 * The target company-month still depends on the booking's company, so the caller must send companyId.
 */
final class RescheduleBooking
{
    public string $roomDayId {
        get => BookingIds::roomDay($this->roomId, $this->slot->day());
    }

    public string $companyMonthId {
        get => BookingIds::companyMonth($this->companyId, $this->slot->month());
    }

    public function __construct(
        public readonly string $bookingId,
        public readonly string $memberId,
        public readonly string $companyId,
        public readonly string $roomId,
        public readonly Slot $slot,
    ) {}

    /**
     * @return list<object>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        BookingState $booking,
        RoomState $room,
        CompanyState $company,
        RoomDayScheduleState $schedule,
        CompanyMonthUsageState $usage,
        ClockInterface $clock,
    ): array {
        $now = $clock->now();
        Violations::throwIfAny([
            !$booking->exists() ? new Violation('booking.not_found', 'Booking does not exist.') : null,
            !$room->exists ? new Violation('room.not_found', 'Room does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $booking->memberId !== $this->memberId ? new Violation('booking.not_owner', 'Only the member who booked can reschedule.') : null,
            $booking->companyId !== $this->companyId ? new Violation('member.company_mismatch', 'Booking was not charged to this company.') : null,
            BookingState::CANCELLED === $booking->status ? new Violation('booking.already_cancelled', 'Booking is cancelled.') : null,
        ]);
        Violations::throwIfAny([
            !BookingPolicy::isRefundable($booking->slot, $now) ? new Violation('booking.too_late_to_reschedule', 'Bookings can only be moved until 24 hours before they start.') : null,
            ...BookingPolicy::slotViolations($this->slot, $now),
            !$schedule->isFree($this->slot, ignoringBookingId: $this->bookingId) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            BookingPolicy::quotaExceeded($company->monthlyMinutes, $usage->usedMinutes(ignoringBookingId: $this->bookingId), $this->slot->minutes()),
        ]);

        return [
            BookingRescheduledFrom::for($this->bookingId, $booking->roomId, $booking->memberId, $booking->companyId, $booking->slot),
            BookingRescheduledTo::for($this->bookingId, $this->roomId, $booking->memberId, $booking->companyId, $this->slot),
        ];
    }
}
