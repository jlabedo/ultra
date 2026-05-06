<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Exception;

class NoTagOverlapError extends \LogicException
{
    public function __construct(string $class, string $method, string $event)
    {
        parent::__construct(sprintf(
            'Handler %s::%s handles %s which shares no tags with entity',
            $class,
            $method,
            $event,
        ));
    }
}
