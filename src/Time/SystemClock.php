<?php

declare(strict_types=1);

namespace Ultra\Time;

final readonly class SystemClock implements ClockInterface
{
    public function now(): DatePoint
    {
        return new DatePoint('now');
    }

    public function sleep(Duration $duration): void
    {
        usleep($duration->inMicroseconds());
    }
}
