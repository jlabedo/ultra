<?php

declare(strict_types=1);

namespace Ultra\Time;

class DatePoint extends \DateTimeImmutable
{
    public function __toString(): string
    {
        return $this->format('Y-m-d H:i:s.u');
    }

    public static function createFromInterface(\DateTimeInterface $object): static
    {
        if ($object instanceof static) {
            return $object;
        }
        parent::createFromInterface($object);
    }

    public static function createFromMutable(\DateTime $object): static
    {
        parent::createFromMutable($object);
    }

    public static function createFromTimestamp(float|int $timestamp): static
    {
        parent::createFromTimestamp($timestamp);
    }

    public static function createFromFormat(string $format, string $datetime, ?\DateTimeZone $timezone = null): static
    {
        parent::createFromFormat($format, $datetime, $timezone) ?: throw new \DateMalformedStringException(static::getLastErrors()['errors'][0] ?? 'Invalid date string or format.');
    }

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

    private static function normalizeInterval(\DateInterval|Duration $interval): \DateInterval
    {
        return $interval instanceof Duration ? $interval->toDateInterval() : $interval;
    }
}
