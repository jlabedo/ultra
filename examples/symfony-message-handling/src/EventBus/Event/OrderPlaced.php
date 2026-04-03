<?php

declare(strict_types=1);

namespace App\EventBus\Event;

use Ultra\Events\Attribute\Event;

#[Event('order.placed')]
final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        public int $amount,
        public string $userId,
    ) {}
}
