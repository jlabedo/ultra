<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Write;

use App\Booking\Domain\Violations;
use App\Booking\EventSourced\Event\RoomOpened;
use App\Booking\EventSourced\State\RoomState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;

final readonly class OpenRoom
{
    public function __construct(
        public string $roomId,
        public string $name,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(RoomState $room): RoomOpened
    {
        Violations::throwIfAny([
            $room->exists ? new Violation('room.already_opened', 'Room already exists.') : null,
        ]);

        return new RoomOpened($this->roomId, $this->name);
    }
}
