<?php

declare(strict_types=1);

namespace Ultra\Mediator;

use Ultra\MessageHandling\MessageContext;

final readonly class ContextPropagatingMediator implements MediatorInterface
{
    public function __construct(
        private MediatorInterface $inner,
        private MessageContext $context,
    ) {}

    public function send(object $message, array|MessageContext $context = []): mixed
    {
        return $this->inner->send($message, $this->context->merge($context));
    }
}
