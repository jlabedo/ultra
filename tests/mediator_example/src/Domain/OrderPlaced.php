<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Domain;

use Ultra\Events\Attribute\Event;

#[Event('order.placed')]
final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
    ) {}
}
