<?php

declare(strict_types=1);

namespace App\EventBus\EventHandler;

use App\EventBus\Event\OrderPlaced;
use App\EventBus\Infrastructure\OrderProjection;
use Ultra\MessageHandling\Attribute\EventHandler;

final class RecordOrderPlaced
{
    #[EventHandler]
    public function __invoke(OrderPlaced $event, OrderProjection $orders): void
    {
        $orders->record($event->orderId);
    }
}
