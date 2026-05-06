<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Exception;

class DuplicateEventSourcingHandlerError extends \LogicException
{
    public function __construct(string $class, string $eventClass, string $existingMethod, string $duplicateMethod)
    {
        parent::__construct(sprintf(
            'Entity %s handles %s more than once: %s and %s.',
            $class,
            $eventClass,
            $existingMethod,
            $duplicateMethod,
        ));
    }
}
