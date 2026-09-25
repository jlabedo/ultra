<?php

declare(strict_types=1);

namespace App\Booking\Domain;

/**
 * A half-open [start, end) time range, always expressed in UTC.
 */
final readonly class Slot
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {}

    public static function of(string $start, string $end): self
    {
        $utc = new \DateTimeZone('UTC');

        return new self(new \DateTimeImmutable($start, $utc), new \DateTimeImmutable($end, $utc));
    }

    public function minutes(): int
    {
        return intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60);
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function day(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function month(): string
    {
        return $this->start->format('Y-m');
    }

    public function shiftedByWeeks(int $weeks): self
    {
        return new self(
            $this->start->modify(sprintf('+%d weeks', $weeks)),
            $this->end->modify(sprintf('+%d weeks', $weeks)),
        );
    }
}
