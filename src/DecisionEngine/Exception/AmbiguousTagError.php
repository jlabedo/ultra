<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Exception;

class AmbiguousTagError extends \LogicException
{
    /**
     * @param list<string> $tags
     */
    public function __construct(string $class, string $method, string $event, array $tags)
    {
        parent::__construct(sprintf(
            'Handler %s::%s handles %s which matches tags [%s] - add #[MatchTag] to disambiguate',
            $class,
            $method,
            $event,
            implode(', ', $tags),
        ));
    }
}
