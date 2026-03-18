<?php

declare(strict_types=1);

namespace Ultra\EventBus;

interface EventBusInterface
{
    public function publish(object $event, array $context = []): void;
}
