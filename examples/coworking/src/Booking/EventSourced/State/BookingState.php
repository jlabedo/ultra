<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\State;

use App\Booking\Domain\Slot;
use App\Booking\EventSourced\Event\BookingCancelled;
use App\Booking\EventSourced\Event\BookingRescheduled;
use App\Booking\EventSourced\Event\RoomBooked;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['booking'])]
final class BookingState
{
    public const string CONFIRMED = 'confirmed';
    public const string CANCELLED = 'cancelled';

    public private(set) ?string $status = null;
    public private(set) string $roomId = '';
    public private(set) string $memberId = '';
    public private(set) string $companyId = '';
    public private(set) ?Slot $slot = null;

    public function exists(): bool
    {
        return null !== $this->status;
    }

    #[EventSourcingHandler]
    public function onBooked(RoomBooked $event): void
    {
        $this->status = self::CONFIRMED;
        $this->roomId = $event->roomId;
        $this->memberId = $event->memberId;
        $this->companyId = $event->companyId;
        $this->slot = new Slot($event->start, $event->end);
    }

    #[EventSourcingHandler]
    public function onRescheduled(BookingRescheduled $event): void
    {
        $this->roomId = $event->roomId;
        $this->slot = new Slot($event->start, $event->end);
    }

    #[EventSourcingHandler]
    public function onCancelled(BookingCancelled $event): void
    {
        $this->status = self::CANCELLED;
    }
}
