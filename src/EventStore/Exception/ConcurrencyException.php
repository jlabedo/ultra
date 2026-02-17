<?php

declare(strict_types=1);

namespace Ultra\EventStore\Exception;

class ConcurrencyException extends \RuntimeException
{
    public function __construct(
        string $dimension,
        string $value,
        int $expectedVersion,
        int $actualVersion,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Version conflict on dimension "%s" for value "%s": expected version %d, actual version %d.',
                $dimension,
                $value,
                $expectedVersion,
                $actualVersion,
            ),
            0,
            $previous,
        );
    }
}
