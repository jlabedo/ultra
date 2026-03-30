<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Exception;

class InvalidMatchTagError extends \LogicException
{
    /**
     * @param list<string> $valid
     */
    public function __construct(string $class, string $method, string $tag, array $valid)
    {
        parent::__construct(sprintf(
            'Handler %s::%s: #[MatchTag(%s)] is not valid - must be in [%s]',
            $class,
            $method,
            $tag,
            implode(', ', $valid),
        ));
    }
}
