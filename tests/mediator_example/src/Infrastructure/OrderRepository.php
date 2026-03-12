<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

use Ultra\Mediator_Example\OrderProcessing\Domain\Order;

final class OrderRepository
{
    /** @var list<Order> */
    public array $saved = [];

    public function save(Order $order): void
    {
        $this->saved[] = $order;
    }
}
