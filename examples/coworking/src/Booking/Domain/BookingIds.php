<?php

declare(strict_types=1);

namespace App\Booking\Domain;

/**
 * Derived identities used as consistency boundaries.
 */
final class BookingIds
{
    public static function roomDay(string $roomId, string $day): string
    {
        return $roomId . '@' . $day;
    }

    public static function companyMonth(string $companyId, string $month): string
    {
        return $companyId . '@' . $month;
    }

    public static function occurrence(string $seriesId, int $index): string
    {
        return $seriesId . '#' . $index;
    }
}
