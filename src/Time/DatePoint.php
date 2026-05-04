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

        return parent::createFromInterface($object);
    }

    public static function createFromMutable(\DateTime $object): static
    {
        return parent::createFromMutable($object);
    }

    public static function createFromTimestamp(float|int $timestamp): static
    {
        return parent::createFromTimestamp($timestamp);
    }

    public static function createFromFormat(string $format, string $datetime, ?\DateTimeZone $timezone = null): static
    {
        return parent::createFromFormat($format, $datetime, $timezone) ?: throw new \DateMalformedStringException(static::getLastErrors()['errors'][0] ?? 'Invalid date string or format.');
    }

    public static function create(int $year = 0, int $month = 1, int $day = 1, int $hour = 0, int $minute = 0, int $second = 0, int $microsecond = 0, ?\DateTimeZone $timezone = null): static
    {
        $instance = new static('1900-01-01 00:00:00', $timezone);

        return $instance->setDate($year, $month, $day)->setTime($hour, $minute, $second, $microsecond);
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

    public function modify(string $modifier): static
    {
        return parent::modify($modifier);
    }

    public function setTimestamp(int $value): static
    {
        return parent::setTimestamp($value);
    }

    public function setDate(int $year, int $month, int $day): static
    {
        return parent::setDate($year, $month, $day);
    }

    public function setISODate(int $year, int $week, int $day = 1): static
    {
        return parent::setISODate($year, $week, $day);
    }

    public function setTime(int $hour, int $minute, int $second = 0, int $microsecond = 0): static
    {
        return parent::setTime($hour, $minute, $second, $microsecond);
    }

    public function setTimezone(\DateTimeZone $timezone): static
    {
        return parent::setTimezone($timezone);
    }

    private static function normalizeInterval(\DateInterval|Duration $interval): \DateInterval
    {
        return $interval instanceof Duration ? $interval->toDateInterval() : $interval;
    }
}
