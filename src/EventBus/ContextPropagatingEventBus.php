<?php

declare(strict_types=1);

namespace Ultra\EventBus;

use Ultra\MessageHandling\MessageContext;

final readonly class ContextPropagatingEventBus implements EventBusInterface
{
    public function __construct(
        private EventBusInterface $inner,
        private MessageContext $context,
    ) {}

    public function publish(object $event, array|MessageContext $context = []): void
    {
        $this->inner->publish($event, $this->context->merge($context));
    }
}
