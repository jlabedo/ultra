<?php

declare(strict_types=1);

namespace Ultra\Time;

final readonly class Duration
{
    private const int MICROSECONDS_PER_SECOND = 1_000_000;
    private const int MICROSECONDS_PER_MINUTE = 60 * self::MICROSECONDS_PER_SECOND;
    private const int MICROSECONDS_PER_HOUR = 60 * self::MICROSECONDS_PER_MINUTE;
    private const int MICROSECONDS_PER_DAY = 24 * self::MICROSECONDS_PER_HOUR;

    public int $microseconds;

    public function __construct(
        int $microseconds = 0,
        int $seconds = 0,
        int $minutes = 0,
        int $hours = 0,
        int $days = 0,
    ) {
        $this->microseconds = $microseconds + $seconds * self::MICROSECONDS_PER_SECOND + $minutes * self::MICROSECONDS_PER_MINUTE + $hours * self::MICROSECONDS_PER_HOUR + $days * self::MICROSECONDS_PER_DAY;
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function microseconds(int $microseconds): self
    {
        return new self($microseconds);
    }

    public static function milliseconds(int|float $milliseconds): self
    {
        return self::fromUnit($milliseconds, 1_000);
    }

    public static function seconds(int|float $seconds): self
    {
        return self::fromUnit($seconds, self::MICROSECONDS_PER_SECOND);
    }

    public static function minutes(int|float $minutes): self
    {
        return self::fromUnit($minutes, self::MICROSECONDS_PER_MINUTE);
    }

    public static function hours(int|float $hours): self
    {
        return self::fromUnit($hours, self::MICROSECONDS_PER_HOUR);
    }

    public static function days(int|float $days): self
    {
        return self::fromUnit($days, self::MICROSECONDS_PER_DAY);
    }

    public function add(self $duration): self
    {
        return new self($this->microseconds + $duration->microseconds);
    }

    public function sub(self $duration): self
    {
        return new self($this->microseconds - $duration->microseconds);
    }

    public function subtract(self $duration): self
    {
        return $this->sub($duration);
    }

    public function negate(): self
    {
        return new self(-$this->microseconds);
    }

    public function toDateInterval(): \DateInterval
    {
        $remaining = abs($this->microseconds);

        $days = intdiv($remaining, self::MICROSECONDS_PER_DAY);
        $remaining %= self::MICROSECONDS_PER_DAY;

        $hours = intdiv($remaining, self::MICROSECONDS_PER_HOUR);
        $remaining %= self::MICROSECONDS_PER_HOUR;

        $minutes = intdiv($remaining, self::MICROSECONDS_PER_MINUTE);
        $remaining %= self::MICROSECONDS_PER_MINUTE;

        $seconds = intdiv($remaining, self::MICROSECONDS_PER_SECOND);
        $remaining %= self::MICROSECONDS_PER_SECOND;

        $interval = new \DateInterval('PT0S');
        $interval->d = $days;
        $interval->h = $hours;
        $interval->i = $minutes;
        $interval->s = $seconds;
        $interval->f = $remaining / self::MICROSECONDS_PER_SECOND;
        $interval->invert = $this->microseconds < 0 ? 1 : 0;

        return $interval;
    }

    private static function fromUnit(int|float $value, int $microsecondsPerUnit): self
    {
        return new self((int) round($value * $microsecondsPerUnit));
    }
}
