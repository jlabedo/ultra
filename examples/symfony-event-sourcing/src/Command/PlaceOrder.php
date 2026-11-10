<?php

declare(strict_types=1);

namespace App\Command;

final readonly class PlaceOrder
{
    public function __construct(
        public string $orderId,
        public string $customer,
    ) {}
}
