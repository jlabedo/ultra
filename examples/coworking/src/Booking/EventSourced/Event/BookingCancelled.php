<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Event;

use App\Booking\Domain\BookingIds;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('coworking.booking_cancelled')]
final class BookingCancelled
{
    public const string BY_MEMBER = 'member';
    public const string ROOM_CLOSED = 'room_closed';

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
        public readonly bool $refunded,
        public readonly string $reason,
    ) {}
}
