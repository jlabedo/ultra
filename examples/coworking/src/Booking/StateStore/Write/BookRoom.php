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
use App\Booking\StateStore\State\MemberState;
use App\Booking\StateStore\State\RoomDayScheduleState;
use App\Booking\StateStore\State\RoomState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\Time\ClockInterface;

final class BookRoom
{
    public string $roomDayId {
        get => BookingIds::roomDay($this->roomId, $this->slot->day());
    }

    public string $companyMonthId {
        get => BookingIds::companyMonth($this->companyId, $this->slot->month());
    }

    /**
     * As in the event-sourced flavor, companyId must be sent by the caller: the company-month row is loaded
     * before the member is.
     */
    public function __construct(
        public readonly string $bookingId,
        public readonly string $roomId,
        public readonly string $memberId,
        public readonly string $companyId,
        public readonly Slot $slot,
    ) {}

    /**
     * The booking parameter is only declared so the aspect registers "this booking row must still be absent".
     *
     * @return list<object>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?BookingState $booking,
        ?RoomState $room,
        ?MemberState $member,
        ?CompanyState $company,
        #[BindTo('roomDayId')]
        ?RoomDayScheduleState $schedule,
        #[BindTo('companyMonthId')]
        ?CompanyMonthUsageState $usage,
        ClockInterface $clock,
    ): array {
        Violations::throwIfAny([
            null !== $booking ? new Violation('booking.already_exists', 'Booking already exists.') : null,
            null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
            null === $member ? new Violation('member.not_found', 'Member does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $member->companyId !== $this->companyId || null === $company ? new Violation('member.company_mismatch', 'Member does not belong to this company.') : null,
        ]);

        $schedule ??= new RoomDayScheduleState($this->roomDayId);
        $usage ??= new CompanyMonthUsageState($this->companyMonthId);
        Violations::throwIfAny([
            ...BookingPolicy::slotViolations($this->slot, $clock->now()),
            !$schedule->isFree($this->slot) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            BookingPolicy::quotaExceeded($company->monthlyMinutes, $usage->usedMinutes(), $this->slot->minutes()),
        ]);

        $booking = new BookingState(
            $this->bookingId,
            $this->roomId,
            $this->memberId,
            $this->companyId,
            $this->slot->start,
            $this->slot->end,
            BookingState::CONFIRMED,
        );

        return [
            $booking,
            $schedule->with($booking),
            $usage->charging($this->bookingId, $this->slot->minutes()),
        ];
    }
}
