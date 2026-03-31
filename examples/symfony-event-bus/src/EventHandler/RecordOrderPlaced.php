<?php

declare(strict_types=1);

namespace App\EventHandler;

use App\Event\OrderPlaced;
use App\Infrastructure\OrderProjection;
use Ultra\EventBus\Attribute\EventHandler;

final class RecordOrderPlaced
{
    #[EventHandler]
    public function __invoke(OrderPlaced $event, OrderProjection $orders): void
    {
        $orders->record($event->orderId);
    }
}
