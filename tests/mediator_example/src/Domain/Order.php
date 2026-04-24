<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Domain;

final readonly class Order
{
    public function __construct(
        public string $orderId,
        public int $amount,
        public string $userId,
    ) {}
}
