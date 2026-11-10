<?php

declare(strict_types=1);

namespace App;

use App\Command\PlaceOrder;
use App\Command\ShipOrder;
use App\Event\OrderPlaced;
use App\Event\OrderShipped;
use App\State\OrderState;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

final class Orders
{
    #[Handles]
    #[DecisionHandler]
    public function place(PlaceOrder $command, OrderState $order): OrderPlaced
    {
        if ('' === trim($command->orderId) || '' === trim($command->customer)) {
            throw new \InvalidArgumentException('Order id and customer must be non-empty.');
        }
        if ($order->placed) {
            throw new \DomainException('An order can only be placed once.');
        }

        return new OrderPlaced($command->orderId, $command->customer);
    }

    #[Handles]
    #[DecisionHandler]
    public function ship(ShipOrder $command, OrderState $order): OrderShipped
    {
        if (!$order->placed || $order->shipped) {
            throw new \DomainException('An order must be placed before it can be shipped once.');
        }

        return new OrderShipped($command->orderId);
    }
}
