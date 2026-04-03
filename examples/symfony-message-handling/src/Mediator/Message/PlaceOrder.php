<?php

declare(strict_types=1);

namespace App\Mediator\Message;

final readonly class PlaceOrder
{
    public function __construct(
        public string $orderId,
        public int $amount,
        public string $userId,
    ) {}
}
