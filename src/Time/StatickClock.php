<?php

declare(strict_types=1);

namespace Ultra\Time;

final class StatickClock implements ClockInterface
{
    private DatePoint $now;

    public function __construct(\DateTimeImmutable $now)
    {
        $this->now = DatePoint::createFromInterface($now);
    }

    public function now(): DatePoint
    {
        return $this->now;
    }

    public function setNow(\DateTimeImmutable $now): void
    {
        $this->now = DatePoint::createFromInterface($now);
    }

    public function sleep(Duration $duration): void
    {
        $this->now = $this->now->add($duration);
    }
}
