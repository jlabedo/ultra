<?php

declare(strict_types=1);

namespace Ultra\EventStore\Exception;

class ConcurrencyException extends \RuntimeException
{
    public function __construct(
        string $streamId,
        int $expectedEventId,
        int $actualEventId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Version conflict on stream "%s": expected last eventId %d, actual last eventId %d.',
                $streamId,
                $expectedEventId,
                $actualEventId,
            ),
            0,
            $previous,
        );
    }
}
