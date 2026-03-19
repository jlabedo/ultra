<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

use Ultra\Mediator\MediatorInterface;

final readonly class ContextPropagatingMediator implements MediatorInterface
{
    public function __construct(
        private MediatorInterface $inner,
        private MessageContext $context,
    ) {}

    public function send(object $message, array $context = []): mixed
    {
        return $this->inner->send($message, $this->context->merge($context)->all());
    }
}
