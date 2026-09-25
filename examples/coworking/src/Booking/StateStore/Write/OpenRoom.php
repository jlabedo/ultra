<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\Violations;
use App\Booking\StateStore\State\RoomState;
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
    public function decide(?RoomState $room): RoomState
    {
        Violations::throwIfAny([
            null !== $room ? new Violation('room.already_opened', 'Room already exists.') : null,
        ]);

        return new RoomState($this->roomId, $this->name);
    }
}
