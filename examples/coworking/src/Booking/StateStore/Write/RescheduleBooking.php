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
use Ultra\StateStore\StateStore;
use Ultra\Time\ClockInterface;

/**
 * Target rows are decision parameters; source rows depend on the loaded booking and are loaded by hand. When
 * source and target are the same row, the parameter instance must be reused, or the second save would overwrite
 * the first one.
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
        ?BookingState $booking,
        ?RoomState $room,
        ?CompanyState $company,
        #[BindTo('roomDayId')]
        ?RoomDayScheduleState $targetSchedule,
        #[BindTo('companyMonthId')]
        ?CompanyMonthUsageState $targetUsage,
        StateStore $stateStore,
        ClockInterface $clock,
    ): array {
        $now = $clock->now();
        Violations::throwIfAny([
            null === $booking ? new Violation('booking.not_found', 'Booking does not exist.') : null,
            null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $booking->memberId !== $this->memberId ? new Violation('booking.not_owner', 'Only the member who booked can reschedule.') : null,
            $booking->companyId !== $this->companyId || null === $company ? new Violation('member.company_mismatch', 'Booking was not charged to this company.') : null,
            BookingState::CANCELLED === $booking->status ? new Violation('booking.already_cancelled', 'Booking is cancelled.') : null,
        ]);

        $targetSchedule ??= new RoomDayScheduleState($this->roomDayId);
        $targetUsage ??= new CompanyMonthUsageState($this->companyMonthId);
        Violations::throwIfAny([
            !BookingPolicy::isRefundable($booking->slot(), $now) ? new Violation('booking.too_late_to_reschedule', 'Bookings can only be moved until 24 hours before they start.') : null,
            ...BookingPolicy::slotViolations($this->slot, $now),
            !$targetSchedule->isFree($this->slot, ignoringBookingId: $this->bookingId) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            BookingPolicy::quotaExceeded($company->monthlyMinutes, $targetUsage->usedMinutes(ignoringBookingId: $this->bookingId), $this->slot->minutes()),
        ]);

        $moved = $booking->movedTo($this->roomId, $this->slot);
        $changes = [$moved];

        if ($booking->roomDayId() === $this->roomDayId) {
            $changes[] = $targetSchedule->with($moved);
        } else {
            $sourceSchedule = $stateStore->load(RoomDayScheduleState::class, $booking->roomDayId());
            $stateStore->expect($sourceSchedule);
            $changes[] = $sourceSchedule->without($this->bookingId);
            $changes[] = $targetSchedule->with($moved);
        }

        if ($booking->companyMonthId() === $this->companyMonthId) {
            $changes[] = $targetUsage->charging($this->bookingId, $this->slot->minutes());
        } else {
            $sourceUsage = $stateStore->load(CompanyMonthUsageState::class, $booking->companyMonthId());
            $stateStore->expect($sourceUsage);
            $changes[] = $sourceUsage->refunding($this->bookingId);
            $changes[] = $targetUsage->charging($this->bookingId, $this->slot->minutes());
        }

        return $changes;
    }
}
