<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Handler;

use Ultra\MessageHandling\MessageContext;

interface MessageHandlerInterface
{
    public function __invoke(object $message, MessageContext $context): mixed;
}
