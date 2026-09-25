<?php

declare(strict_types=1);

namespace App\Booking\StateStore\State;

use App\Booking\Domain\BookingIds;
use App\Booking\Domain\Slot;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'cw_ss_bookings')]
final readonly class BookingState
{
    public const string CONFIRMED = 'confirmed';
    public const string CANCELLED = 'cancelled';

    public function __construct(
        #[StateId(column: 'booking_id')]
        public string $bookingId,
        #[StateColumn('room_id')]
        public string $roomId,
        #[StateColumn('member_id')]
        public string $memberId,
        #[StateColumn('company_id')]
        public string $companyId,
        #[StateColumn('start_at')]
        public \DateTimeImmutable $start,
        #[StateColumn('end_at')]
        public \DateTimeImmutable $end,
        #[StateColumn('status')]
        public string $status,
    ) {}

    public function slot(): Slot
    {
        return new Slot($this->start, $this->end);
    }

    public function roomDayId(): string
    {
        return BookingIds::roomDay($this->roomId, $this->slot()->day());
    }

    public function companyMonthId(): string
    {
        return BookingIds::companyMonth($this->companyId, $this->slot()->month());
    }

    public function cancelled(): self
    {
        return new self($this->bookingId, $this->roomId, $this->memberId, $this->companyId, $this->start, $this->end, self::CANCELLED);
    }

    public function movedTo(string $roomId, Slot $slot): self
    {
        return new self($this->bookingId, $roomId, $this->memberId, $this->companyId, $slot->start, $slot->end, $this->status);
    }
}
