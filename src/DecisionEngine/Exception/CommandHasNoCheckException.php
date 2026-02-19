<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Exception;

class CommandHasNoCheckException extends \LogicException
{
    public function __construct(string $commandClass)
    {
        parent::__construct(sprintf('Command %s has no check() method.', $commandClass));
    }
}
