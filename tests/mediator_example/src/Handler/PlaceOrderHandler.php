<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Handler;

use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator_Example\OrderProcessing\Domain\Order;
use Ultra\Mediator_Example\OrderProcessing\Domain\OrderPlaced;
use Ultra\Mediator_Example\OrderProcessing\Domain\PlaceOrder;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\EventBus;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\OrderRepository;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\PaymentGateway;

final class PlaceOrderHandler
{
    #[Handles]
    public function handle(
        PlaceOrder $command,
        OrderRepository $orders,
        PaymentGateway $payments,
        EventBus $bus,
    ): string {
        $payments->charge($command->orderId, $command->amount);

        $order = new Order($command->orderId, $command->amount, $command->userId);
        $orders->save($order);
        $bus->dispatch(new OrderPlaced($command->orderId));

        return $command->orderId;
    }
}
