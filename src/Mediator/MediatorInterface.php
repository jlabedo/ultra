<?php

declare(strict_types=1);

namespace Ultra\Mediator;

use Ultra\MessageHandling\MessageContext;

interface MediatorInterface
{
    public function send(object $message, array|MessageContext $context = []): mixed;
}
