<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Event;

use App\Booking\Domain\BookingIds;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

/**
 * Moves a booking to a new slot, possibly in another room, day or month.
 *
 * Tags are single-valued, so the boundaries it leaves are tagged under previous* names; the room-day and
 * company-month states load them through tag aliases.
 */
#[Event('coworking.booking_rescheduled')]
final class BookingRescheduled
{
    #[DomainIdentifier('roomDay')]
    public string $roomDayId {
        get => BookingIds::roomDay($this->roomId, $this->start->format('Y-m-d'));
    }

    #[DomainIdentifier('previousRoomDay')]
    public string $previousRoomDayId {
        get => BookingIds::roomDay($this->previousRoomId, $this->previousStart->format('Y-m-d'));
    }

    #[DomainIdentifier('companyMonth')]
    public string $companyMonthId {
        get => BookingIds::companyMonth($this->companyId, $this->start->format('Y-m'));
    }

    #[DomainIdentifier('previousCompanyMonth')]
    public string $previousCompanyMonthId {
        get => BookingIds::companyMonth($this->companyId, $this->previousStart->format('Y-m'));
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
        public readonly string $previousRoomId,
        public readonly \DateTimeImmutable $previousStart,
        public readonly \DateTimeImmutable $previousEnd,
    ) {}
}
