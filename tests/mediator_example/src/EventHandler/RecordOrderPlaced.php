<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\EventHandler;

use Ultra\EventBus\Attribute\EventHandler;
use Ultra\Mediator_Example\OrderProcessing\Domain\OrderPlaced;
use Ultra\Mediator_Example\OrderProcessing\Infrastructure\PublishedEvents;

final class RecordOrderPlaced
{
    public function __construct(private readonly PublishedEvents $publishedEvents)
    {
    }

    #[EventHandler]
    public function record(OrderPlaced $event): void
    {
        $this->publishedEvents->record($event);
    }
}
