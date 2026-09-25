<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Event;

use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

#[Event('coworking.room_opened')]
final readonly class RoomOpened
{
    public function __construct(
        #[DomainIdentifier('room')]
        public string $roomId,
        public string $name,
    ) {}
}
