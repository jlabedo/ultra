<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\BookingIds;
use App\Booking\StateStore\State\BookingState;
use App\Booking\StateStore\State\CompanyMonthUsageState;
use App\Booking\StateStore\State\RoomDayScheduleState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StateStore\StateStore;

/**
 * Every cancelled booking and every refunded company-month is a separate row, discovered from the schedule.
 */
final readonly class CloseRoomForDay
{
    public function __construct(
        public string $roomId,
        public string $day,
    ) {}

    public function roomDayId(): string
    {
        return BookingIds::roomDay($this->roomId, $this->day);
    }

    /**
     * @return list<object>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(?RoomDayScheduleState $schedule, StateStore $stateStore): array
    {
        if (null === $schedule) {
            return [];
        }

        $changes = [new RoomDayScheduleState($this->roomDayId())];
        /** @var array<string, CompanyMonthUsageState> $usages */
        $usages = [];
        foreach ($schedule->slots as $slot) {
            $booking = $stateStore->load(BookingState::class, $slot->bookingId);
            $stateStore->expect($booking);
            $changes[] = $booking->cancelled();

            $companyMonthId = $booking->companyMonthId();
            if (!isset($usages[$companyMonthId])) {
                $usages[$companyMonthId] = $stateStore->load(CompanyMonthUsageState::class, $companyMonthId);
                $stateStore->expect($usages[$companyMonthId]);
            }
            $usages[$companyMonthId] = $usages[$companyMonthId]->refunding($slot->bookingId);
        }

        return [...$changes, ...array_values($usages)];
    }
}
