<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\State;

use App\Booking\EventSourced\Event\RoomOpened;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['room'])]
final class RoomState
{
    public private(set) bool $exists = false;

    #[EventSourcingHandler]
    public function onOpened(RoomOpened $event): void
    {
        $this->exists = true;
    }
}
