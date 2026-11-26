<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\Duration;

final readonly class AsyncRetryPolicy
{
    public function __construct(
        public int $maxAttempts = 5,
        public ?Duration $retryDelay = null,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 2_147_483_647) {
            throw new \InvalidArgumentException('Async maxAttempts must be between 1 and 2147483647.');
        }

        if (null !== $retryDelay && $retryDelay->inMicroseconds() < 0) {
            throw new \InvalidArgumentException('Async retryDelay must be non-negative.');
        }
    }
}
