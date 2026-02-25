<?php

declare(strict_types=1);

namespace Ultra\Mediator\Exception;

final class NoHandlerFound extends \RuntimeException
{
    public function __construct(string $messageClass)
    {
        parent::__construct(sprintf('No handler found for message "%s"', $messageClass));
    }
}
