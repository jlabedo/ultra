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
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\Time\ClockInterface;

/**
 * As in the event-sourced flavor, the company and company-month rows are identified by the member's company and
 * loaded once the member is. They are null when the member is missing.
 */
final readonly class BookRoom
{
    public function __construct(
        public string $bookingId,
        public string $roomId,
        public string $memberId,
        public Slot $slot,
    ) {}

    public function roomDayId(): string
    {
        return BookingIds::roomDay($this->roomId, $this->slot->day());
    }

    public function companyId(MemberState $member): string
    {
        return $member->companyId;
    }

    public function companyMonthId(MemberState $member): string
    {
        return BookingIds::companyMonth($member->companyId, $this->slot->month());
    }

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
        ?RoomDayScheduleState $schedule,
        ?CompanyMonthUsageState $usage,
        ClockInterface $clock,
    ): array {
        Violations::throwIfAny([
            null !== $booking ? new Violation('booking.already_exists', 'Booking already exists.') : null,
            null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
            null === $member ? new Violation('member.not_found', 'Member does not exist.') : null,
        ]);
        Violations::throwIfAny([
            null === $company ? new Violation('company.not_found', 'Company does not exist.') : null,
        ]);

        $schedule ??= new RoomDayScheduleState($this->roomDayId());
        $usage ??= new CompanyMonthUsageState($this->companyMonthId($member));
        Violations::throwIfAny([
            ...BookingPolicy::slotViolations($this->slot, $clock->now()),
            !$schedule->isFree($this->slot) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            BookingPolicy::quotaExceeded($company->monthlyMinutes, $usage->usedMinutes(), $this->slot->minutes()),
        ]);

        $booking = new BookingState(
            $this->bookingId,
            $this->roomId,
            $this->memberId,
            $member->companyId,
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
