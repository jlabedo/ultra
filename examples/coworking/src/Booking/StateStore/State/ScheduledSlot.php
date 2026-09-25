<?php

declare(strict_types=1);

namespace App\Booking\StateStore\State;

use App\Booking\Domain\Slot;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

/**
 * A copy of the booking's slot, kept in the room-day schedule so overlaps can be checked by loading one state.
 */
#[State(table: 'cw_ss_room_day_slots')]
final readonly class ScheduledSlot
{
    public function __construct(
        #[StateId(column: 'booking_id')]
        public string $bookingId,
        #[StateColumn('member_id')]
        public string $memberId,
        #[StateColumn('company_id')]
        public string $companyId,
        #[StateColumn('start_at')]
        public \DateTimeImmutable $start,
        #[StateColumn('end_at')]
        public \DateTimeImmutable $end,
    ) {}

    public function slot(): Slot
    {
        return new Slot($this->start, $this->end);
    }
}
