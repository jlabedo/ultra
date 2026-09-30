<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect\State;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'cw_direct_rooms')]
final readonly class Room
{
    public function __construct(
        #[StateId]
        public string $id,
        public string $name,
    ) {}
}
