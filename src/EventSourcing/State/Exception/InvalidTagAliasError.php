<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Exception;

class InvalidTagAliasError extends \LogicException
{
    public function __construct(string $class, string $reason)
    {
        parent::__construct(sprintf('Event-sourced entity %s: %s.', $class, $reason));
    }
}
