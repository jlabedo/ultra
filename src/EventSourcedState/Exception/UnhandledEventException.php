<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Exception;

class UnhandledEventException extends \LogicException
{
    public function __construct(string $eventType, string $entityClass)
    {
        parent::__construct(sprintf(
            'Unhandled event %s while replaying %s.',
            $eventType,
            $entityClass,
        ));
    }
}
