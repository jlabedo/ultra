<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\Slot;

/**
 * All-or-nothing weekly series. Each occurrence has its own room-day and company-month boundary, and the number
 * of occurrences is only known at runtime, so the series cannot be one decision handler.
 *
 * Instead, one BookRoom decision runs per occurrence inside the same unit of work: the mapped-table unit of work
 * makes earlier occurrences visible to later ones, and the whole series is written in one flush.
 */
final readonly class BookWeekly
{
    public function __construct(
        public string $seriesId,
        public string $roomId,
        public string $memberId,
        public string $companyId,
        public Slot $firstSlot,
        public int $weeks,
    ) {}
}
