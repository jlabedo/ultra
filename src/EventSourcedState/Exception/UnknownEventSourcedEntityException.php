<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Exception;

class UnknownEventSourcedEntityException extends \LogicException
{
    public function __construct(string $entityClass)
    {
        parent::__construct(sprintf(
            'No event-sourced entity loader is registered for %s.',
            $entityClass,
        ));
    }
}
