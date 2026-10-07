<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\State;

use App\Booking\Domain\Slot;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'cw_direct_bookings')]
final readonly class Booking
{
    public const string CONFIRMED = 'confirmed';
    public const string CANCELLED = 'cancelled';

    public function __construct(
        #[StateId]
        public string $id,
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
        public string $status = self::CONFIRMED,
        public bool $charged = true,
    ) {}

    public function slot(): Slot
    {
        return new Slot($this->start, $this->end);
    }

    public function cancelled(bool $refunded): self
    {
        return new self(
            $this->id,
            $this->roomId,
            $this->memberId,
            $this->companyId,
            $this->start,
            $this->end,
            self::CANCELLED,
            !$refunded,
        );
    }

    public function movedTo(string $roomId, Slot $slot): self
    {
        return new self(
            $this->id,
            $roomId,
            $this->memberId,
            $this->companyId,
            $slot->start,
            $slot->end,
            $this->status,
            $this->charged,
        );
    }
}
