<?php

declare(strict_types=1);

namespace Ultra\Time;

use Psr\Clock\ClockInterface;

final class StatickClock implements ClockInterface
{
    private DatePoint $now;

    public function __construct(\DateTimeImmutable $now)
    {
        $this->now = self::normalize($now);
    }

    public function now(): DatePoint
    {
        return $this->now;
    }

    public function setNow(\DateTimeImmutable $now): void
    {
        $this->now = self::normalize($now);
    }

    public function advance(\DateInterval|Duration $duration): DatePoint
    {
        return $this->now = $this->now->add($duration);
    }

    public function rewind(\DateInterval|Duration $duration): DatePoint
    {
        return $this->now = $this->now->sub($duration);
    }

    private static function normalize(\DateTimeImmutable $now): DatePoint
    {
        if ($now instanceof DatePoint) {
            return $now;
        }

        return new DatePoint($now->format('Y-m-d H:i:s.u'), $now->getTimezone());
    }
}
