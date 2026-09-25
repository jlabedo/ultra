<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Event;

use App\Booking\Domain\BookingIds;
use App\Booking\Domain\Slot;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

/**
 * Booking confirmed for a slot.
 */
#[Event('coworking.room_booked')]
final readonly class RoomBooked
{
    /**
     * Derived tags are stored in the payload: the mapper would try to write into virtual (hooked) properties.
     * Use {@see self::for()} so they always match the booking data.
     */
    public function __construct(
        #[DomainIdentifier('booking')]
        public string $bookingId,
        #[DomainIdentifier('room')]
        public string $roomId,
        #[DomainIdentifier('member')]
        public string $memberId,
        #[DomainIdentifier('company')]
        public string $companyId,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        #[DomainIdentifier('roomDay')]
        public string $roomDayId,
        #[DomainIdentifier('companyMonth')]
        public string $companyMonthId,
    ) {}

    public static function for(string $bookingId, string $roomId, string $memberId, string $companyId, Slot $slot): self
    {
        return new self(
            $bookingId,
            $roomId,
            $memberId,
            $companyId,
            $slot->start,
            $slot->end,
            BookingIds::roomDay($roomId, $slot->day()),
            BookingIds::companyMonth($companyId, $slot->month()),
        );
    }
}
