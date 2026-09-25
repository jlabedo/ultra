<?php

declare(strict_types=1);

namespace App\Booking\StateStore\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'cw_ss_rooms')]
final readonly class RoomState
{
    public function __construct(
        #[StateId(column: 'room_id')]
        public string $roomId,
        #[StateColumn('name')]
        public string $name,
    ) {}
}
