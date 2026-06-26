<?php

declare(strict_types=1);

namespace Ultra\Signal;

use Ultra\Time\DatePoint;

final readonly class VisibilitySnapshot
{
    public function __construct(
        public string $snapshot,
        public DatePoint $visibleAt,
    ) {
        if ('' === $snapshot) {
            throw new \InvalidArgumentException('Signal visibility snapshot must be non-empty.');
        }
    }
}
