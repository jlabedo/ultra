<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Exception;

class MissingEventSourcingHandlerError extends \LogicException
{
    public function __construct(string $class, string $method)
    {
        parent::__construct(sprintf(
            '%s::%s has #[MatchTag] but is not an #[EventSourcingHandler]',
            $class,
            $method,
        ));
    }
}
