<?php

declare(strict_types=1);

namespace Ultra\EventStore\Exception;

class ConcurrencyException extends \RuntimeException
{
    public function __construct(
        string $subject,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Query snapshot changed for "%s".',
                $subject,
            ),
            0,
            $previous,
        );
    }
}
