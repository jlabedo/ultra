<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Domain;

final readonly class OrderPlaced
{
    public function __construct(public string $orderId)
    {
    }
}
