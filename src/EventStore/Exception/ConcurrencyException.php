<?php

declare(strict_types=1);

namespace Ultra\EventStore\Exception;

class ConcurrencyException extends \RuntimeException
{
    public function __construct(
        string $subject,
        int $expectedVersion,
        int $actualVersion,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Version conflict on "%s": expected version %d, actual version %d.',
                $subject,
                $expectedVersion,
                $actualVersion,
            ),
            0,
            $previous,
        );
    }
}
