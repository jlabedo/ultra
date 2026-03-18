<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

final class PublishedEvents
{
    /** @var list<object> */
    public array $events = [];

    public function record(object $event): void
    {
        $this->events[] = $event;
    }
}
