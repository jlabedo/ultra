<?php

declare(strict_types=1);

namespace Ultra\EventStore\Exception;

class ConcurrencyException extends \RuntimeException
{
    public function __construct(
        string $subject,
        int $expectedEventId,
        int $actualEventId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Version conflict on "%s": expected last eventId %d, actual last eventId %d.',
                $subject,
                $expectedEventId,
                $actualEventId,
            ),
            0,
            $previous,
        );
    }
}
