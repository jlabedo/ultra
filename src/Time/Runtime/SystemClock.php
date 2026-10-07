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
        $microseconds = $duration->inMicroseconds();
        if ($microseconds < 0) {
            throw new \ValueError('usleep(): Argument #1 ($microseconds) must be greater than or equal to 0');
        }
        usleep($microseconds);
    }
}
