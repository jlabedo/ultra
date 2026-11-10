<?php

declare(strict_types=1);

namespace App\Command;

final readonly class ShipOrder
{
    public function __construct(
        public string $orderId,
    ) {}
}
