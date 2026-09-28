<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\BookingIds;
use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Slot;
use App\Booking\Domain\Violations;
use App\Booking\StateStore\State\BookingState;
use App\Booking\StateStore\State\CompanyMonthUsageState;
use App\Booking\StateStore\State\CompanyState;
use App\Booking\StateStore\State\RoomDayScheduleState;
use App\Booking\StateStore\State\RoomState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\Time\ClockInterface;

/**
 * Every row is a decision parameter. The company and the source rows are identified by the loaded booking, so they
 * are loaded in a second round and are null when the booking is missing.
 *
 * Source and target can be the same row (same room-day, or same company-month). Both parameters then hold an equal
 * copy of that row, with the same expectation registered twice, and only the target copy is returned: returning
 * both would make the second save overwrite the first one.
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

    public function sourceRoomDayId(BookingState $booking): string
    {
        return $booking->roomDayId();
    }

    public function sourceCompanyMonthId(BookingState $booking): string
    {
        return $booking->companyMonthId();
    }

    /**
     * @return list<object>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?BookingState $booking,
        ?RoomState $room,
        ?CompanyState $company,
        ?RoomDayScheduleState $targetSchedule,
        ?CompanyMonthUsageState $targetUsage,
        #[BindTo(method: 'sourceRoomDayId')]
        ?RoomDayScheduleState $sourceSchedule,
        #[BindTo(method: 'sourceCompanyMonthId')]
        ?CompanyMonthUsageState $sourceUsage,
        ClockInterface $clock,
    ): array {
        $now = $clock->now();
        Violations::throwIfAny([
            null === $booking ? new Violation('booking.not_found', 'Booking does not exist.') : null,
            null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $booking->memberId !== $this->memberId ? new Violation('booking.not_owner', 'Only the member who booked can reschedule.') : null,
            null === $company ? new Violation('company.not_found', 'Company does not exist.') : null,
            BookingState::CANCELLED === $booking->status ? new Violation('booking.already_cancelled', 'Booking is cancelled.') : null,
        ]);

        $targetSchedule ??= new RoomDayScheduleState($this->roomDayId());
        $targetUsage ??= new CompanyMonthUsageState($this->companyMonthId($booking));
        Violations::throwIfAny([
            !BookingPolicy::isRefundable($booking->slot(), $now) ? new Violation('booking.too_late_to_reschedule', 'Bookings can only be moved until 24 hours before they start.') : null,
            ...BookingPolicy::slotViolations($this->slot, $now),
            !$targetSchedule->isFree($this->slot, ignoringBookingId: $this->bookingId) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            BookingPolicy::quotaExceeded($company->monthlyMinutes, $targetUsage->usedMinutes(ignoringBookingId: $this->bookingId), $this->slot->minutes()),
        ]);

        $moved = $booking->movedTo($this->roomId, $this->slot);
        $changes = [$moved, $targetSchedule->with($moved), $targetUsage->charging($this->bookingId, $this->slot->minutes())];

        if (null !== $sourceSchedule && $booking->roomDayId() !== $this->roomDayId()) {
            $changes[] = $sourceSchedule->without($this->bookingId);
        }
        if (null !== $sourceUsage && $booking->companyMonthId() !== $this->companyMonthId($booking)) {
            $changes[] = $sourceUsage->refunding($this->bookingId);
        }

        return $changes;
    }
}
