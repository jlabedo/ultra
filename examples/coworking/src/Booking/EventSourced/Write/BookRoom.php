<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Write;

use App\Booking\Domain\BookingIds;
use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Slot;
use App\Booking\Domain\Violations;
use App\Booking\EventSourced\Event\RoomBooked;
use App\Booking\EventSourced\State\BookingState;
use App\Booking\EventSourced\State\CompanyMonthUsageState;
use App\Booking\EventSourced\State\CompanyState;
use App\Booking\EventSourced\State\MemberState;
use App\Booking\EventSourced\State\RoomDayScheduleState;
use App\Booking\EventSourced\State\RoomState;
use Ultra\MessageHandling\Attribute\SelfHandler;
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
     * The member's company is part of the member state, but the company-month boundary must be known before
     * anything is loaded. The caller therefore has to send companyId, and decide() verifies it.
     */
    public function __construct(
        public readonly string $bookingId,
        public readonly string $roomId,
        public readonly string $memberId,
        public readonly string $companyId,
        public readonly Slot $slot,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        BookingState $booking,
        RoomState $room,
        MemberState $member,
        CompanyState $company,
        RoomDayScheduleState $schedule,
        CompanyMonthUsageState $usage,
        ClockInterface $clock,
    ): RoomBooked {
        Violations::throwIfAny([
            $booking->exists() ? new Violation('booking.already_exists', 'Booking already exists.') : null,
            !$room->exists ? new Violation('room.not_found', 'Room does not exist.') : null,
            !$member->exists ? new Violation('member.not_found', 'Member does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $member->companyId !== $this->companyId ? new Violation('member.company_mismatch', 'Member does not belong to this company.') : null,
        ]);
        Violations::throwIfAny([
            ...BookingPolicy::slotViolations($this->slot, $clock->now()),
            !$schedule->isFree($this->slot) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            BookingPolicy::quotaExceeded($company->monthlyMinutes, $usage->usedMinutes(), $this->slot->minutes()),
        ]);

        return new RoomBooked($this->bookingId, $this->roomId, $this->memberId, $this->companyId, $this->slot->start, $this->slot->end);
    }
}
