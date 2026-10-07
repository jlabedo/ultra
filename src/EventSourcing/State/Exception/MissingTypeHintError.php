<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Exception;

class MissingTypeHintError extends \LogicException
{
    public function __construct(string $class, string $method)
    {
        parent::__construct(sprintf('Handler %s::%s must type-hint an event class', $class, $method));
    }
}
