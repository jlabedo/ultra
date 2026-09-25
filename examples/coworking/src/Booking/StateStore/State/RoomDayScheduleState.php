<?php

declare(strict_types=1);

namespace App\Booking\StateStore\State;

use App\Booking\Domain\Slot;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateId;

/**
 * Consistency boundary for overlaps. The parent row carries nothing but the id: it exists to own the slots.
 */
#[State(table: 'cw_ss_room_days')]
final readonly class RoomDayScheduleState
{
    /**
     * @param list<ScheduledSlot> $slots
     */
    public function __construct(
        #[StateId(column: 'room_day_id')]
        public string $roomDayId,
        #[StateCollection(itemClass: ScheduledSlot::class)]
        public array $slots = [],
    ) {}

    public function isFree(Slot $slot, ?string $ignoringBookingId = null): bool
    {
        foreach ($this->slots as $scheduled) {
            if ($scheduled->bookingId !== $ignoringBookingId && $scheduled->slot()->overlaps($slot)) {
                return false;
            }
        }

        return true;
    }

    public function with(BookingState $booking): self
    {
        return new self($this->roomDayId, [
            ...$this->without($booking->bookingId)->slots,
            new ScheduledSlot($booking->bookingId, $booking->memberId, $booking->companyId, $booking->start, $booking->end),
        ]);
    }

    public function without(string $bookingId): self
    {
        return new self($this->roomDayId, array_values(array_filter(
            $this->slots,
            static fn (ScheduledSlot $slot): bool => $slot->bookingId !== $bookingId,
        )));
    }
}
