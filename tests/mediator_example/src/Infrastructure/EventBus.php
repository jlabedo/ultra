<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

final class EventBus
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): void
    {
        $this->events[] = $event;
    }
}
