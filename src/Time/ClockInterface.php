<?php

namespace Ultra\Time;

interface ClockInterface extends \Psr\Clock\ClockInterface
{
    /**
     * Returns the current time as a DatePoint Object.
     */
    public function now(): DatePoint;

    public function sleep(Duration $duration): void;
}
