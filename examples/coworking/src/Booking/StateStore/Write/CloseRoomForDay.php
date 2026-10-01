<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\StateStore\State\BookingState;
use App\Booking\StateStore\State\RoomState;
use App\Booking\StateStore\Repository\BookingRepository;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final readonly class CloseRoomForDay
{
    public function __construct(
        public string $roomId,
        public string $day,
    ) {}

    /** @return list<BookingState> */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(?RoomState $room, BookingRepository $bookings): array
    {
        if (null === $room) {
            return [];
        }

        return array_map(
            static fn (BookingState $booking): BookingState => $booking->cancelled(refunded: true),
            $bookings->observeConfirmedOnDay($room->roomId, $this->day),
        );
    }
}
