<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Write;

use App\Booking\Domain\BookingIds;
use App\Booking\EventSourced\Event\BookingCancelled;
use App\Booking\EventSourced\State\RoomDayScheduleState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

/**
 * Cancels and refunds every booking of a room on one day. Refunds need no loading: company usage is derived
 * from the cancellation events, which are routed to each company-month by their tags.
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
     * @return list<BookingCancelled>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(RoomDayScheduleState $schedule): array
    {
        $events = [];
        foreach ($schedule->bookings as $bookingId => $booking) {
            $events[] = new BookingCancelled(
                $bookingId,
                $this->roomId,
                $booking['memberId'],
                $booking['companyId'],
                $booking['slot']->start,
                $booking['slot']->end,
                refunded: true,
                reason: BookingCancelled::ROOM_CLOSED,
            );
        }

        return $events;
    }
}
