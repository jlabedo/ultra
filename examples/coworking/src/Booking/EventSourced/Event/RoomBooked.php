<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Event;

use App\Booking\Domain\BookingIds;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

/**
 * Booking confirmed for a slot.
 */
#[Event('coworking.room_booked')]
final class RoomBooked
{
    #[DomainIdentifier('roomDay')]
    public string $roomDayId {
        get => BookingIds::roomDay($this->roomId, $this->start->format('Y-m-d'));
    }

    #[DomainIdentifier('companyMonth')]
    public string $companyMonthId {
        get => BookingIds::companyMonth($this->companyId, $this->start->format('Y-m'));
    }

    public function __construct(
        #[DomainIdentifier('booking')]
        public readonly string $bookingId,
        #[DomainIdentifier('room')]
        public readonly string $roomId,
        #[DomainIdentifier('member')]
        public readonly string $memberId,
        #[DomainIdentifier('company')]
        public readonly string $companyId,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
    ) {}
}
