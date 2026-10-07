<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\Slot;
use App\Booking\StateStore\BookingPlanner;
use App\Booking\StateStore\State\BookingState;
use App\Booking\StateStore\State\CompanyState;
use App\Booking\StateStore\State\MemberState;
use App\Booking\StateStore\State\RoomState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final readonly class BookRoom
{
    public function __construct(
        public string $bookingId,
        public string $roomId,
        public string $memberId,
        public Slot $slot,
    ) {}

    public function companyId(MemberState $member): string
    {
        return $member->companyId;
    }

    /** @return list<BookingState> */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?RoomState $room,
        ?MemberState $member,
        ?CompanyState $company,
        BookingPlanner $planner,
    ): array {
        return $planner->plan([['id' => $this->bookingId, 'slot' => $this->slot]], $room, $member, $company);
    }
}
