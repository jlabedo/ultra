<?php

declare(strict_types=1);

namespace Ultra\Mediator;

use Ultra\Mediator\Exception\NoHandlerFound;
use Ultra\MessageHandling\MessageContext;

class EmptyMediator implements MediatorInterface
{
    public function send(object $message, array|MessageContext $context = []): mixed
    {
        throw new NoHandlerFound($message::class);
    }
}