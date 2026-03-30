<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Exception;

class UnnecessaryMatchTagError extends \LogicException
{
    public function __construct(string $class, string $method, string $event, string $tag)
    {
        parent::__construct(sprintf(
            'Handler %s::%s: #[MatchTag] is unnecessary - %s only matches tag %s',
            $class,
            $method,
            $event,
            $tag,
        ));
    }
}
