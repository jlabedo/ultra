<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Exception;

use Ultra\StatefulMessageHandling\Violation;

class InvariantViolation extends \DomainException
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(
        public readonly array $violations,
        ?\Throwable $previous = null,
    ) {
        $messages = array_map(
            static fn (Violation $v): string => $v->code.': '.$v->message,
            $violations,
        );
        parent::__construct(
            implode('; ', $messages),
            0,
            $previous,
        );
    }
}
