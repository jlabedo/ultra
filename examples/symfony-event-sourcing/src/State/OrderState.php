<?php

declare(strict_types=1);

namespace App\State;

use App\Event\OrderPlaced;
use App\Event\OrderShipped;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\Attribute\EventSourcingHandler;

#[EventSourcedEntity(tags: ['order'])]
final class OrderState
{
    public private(set) bool $placed = false;
    public private(set) bool $shipped = false;

    #[EventSourcingHandler]
    public function onPlaced(OrderPlaced $event): void
    {
        $this->placed = true;
    }

    #[EventSourcingHandler]
    public function onShipped(OrderShipped $event): void
    {
        $this->shipped = true;
    }
}
