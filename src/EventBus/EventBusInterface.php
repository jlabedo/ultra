<?php

declare(strict_types=1);

namespace Ultra\EventBus;

use Ultra\MessageHandling\MessageContext;

interface EventBusInterface
{
    public function publish(object $event, array|MessageContext $context = []): void;
}
