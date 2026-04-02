<?php

declare(strict_types=1);

namespace App\Handler;

use App\Infrastructure\OrderRepository;
use App\Message\PlaceOrder;
use Ultra\MessageHandling\Attribute\Handles;

final class PlaceOrderHandler
{
    #[Handles]
    public function __invoke(PlaceOrder $command, OrderRepository $orders): string
    {
        $orders->save($command->orderId);

        return strtoupper($command->orderId);
    }
}
