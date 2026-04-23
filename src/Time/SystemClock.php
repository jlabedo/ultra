<?php

declare(strict_types=1);

namespace Ultra\Time;

use Psr\Clock\ClockInterface;

final readonly class SystemClock implements ClockInterface
{
    public function now(): DatePoint
    {
        return new DatePoint('now');
    }
}
