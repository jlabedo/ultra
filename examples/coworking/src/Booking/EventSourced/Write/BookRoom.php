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

/**
 * The company and its month quota are identified by the member's company: they are loaded in a second round,
 * once the member is.
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
            ...BookingPolicy::slotViolations($this->slot, $clock->now()),
            !$schedule->isFree($this->slot) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            BookingPolicy::quotaExceeded($company->monthlyMinutes, $usage->usedMinutes(), $this->slot->minutes()),
        ]);

        return new RoomBooked($this->bookingId, $this->roomId, $this->memberId, $member->companyId, $this->slot->start, $this->slot->end);
    }
}
