<?php

declare(strict_types=1);

namespace Ultra\Time;

class DatePoint extends \DateTimeImmutable
{
    public function add(\DateInterval|Duration $interval): static
    {
        $datePoint = parent::add(self::normalizeInterval($interval));

        if ($datePoint instanceof static) {
            return $datePoint;
        }

        return new static($datePoint->format('Y-m-d H:i:s.u'), $datePoint->getTimezone());
    }

    public function sub(\DateInterval|Duration $interval): static
    {
        $datePoint = parent::sub(self::normalizeInterval($interval));

        if ($datePoint instanceof static) {
            return $datePoint;
        }

        return new static($datePoint->format('Y-m-d H:i:s.u'), $datePoint->getTimezone());
    }

    public function subtract(\DateInterval|Duration $interval): static
    {
        return $this->sub($interval);
    }

    private static function normalizeInterval(\DateInterval|Duration $interval): \DateInterval
    {
        return $interval instanceof Duration ? $interval->toDateInterval() : $interval;
    }
}
