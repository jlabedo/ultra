<?php

declare(strict_types=1);

namespace App\Booking\Domain;

use Ultra\StatefulMessageHandling\Violation;

/**
 * Pure booking rules shared by every persistence flavor, so the flavors only differ in how state is loaded and saved.
 */
final class BookingPolicy
{
    public const int OPENING_HOUR = 8;
    public const int CLOSING_HOUR = 20;
    public const int GRID_MINUTES = 30;
    public const int MAX_MINUTES = 240;
    public const int MAX_DAYS_AHEAD = 90;
    public const int FREE_CANCELLATION_HOURS = 24;

    /**
     * @return list<Violation>
     */
    public static function slotViolations(Slot $slot, \DateTimeImmutable $now): array
    {
        if ($slot->end <= $slot->start) {
            return [new Violation('slot.invalid_range', 'A slot must end after it starts.')];
        }

        $violations = [];
        if (
            0 !== ($slot->start->getTimestamp() % (self::GRID_MINUTES * 60))
            || 0 !== ($slot->end->getTimestamp() % (self::GRID_MINUTES * 60))
        ) {
            $violations[] = new Violation('slot.off_grid', sprintf(
                'Slots must start and end on a %d minutes grid.',
                self::GRID_MINUTES,
            ));
        }
        if (
            $slot->day() !== $slot->end->modify('-1 second')->format('Y-m-d')
            || (int) $slot->start->format('G') < self::OPENING_HOUR
            || $slot->end > $slot->start->setTime(self::CLOSING_HOUR, 0)
        ) {
            $violations[] = new Violation('slot.outside_opening_hours', sprintf(
                'Rooms are open from %d:00 to %d:00.',
                self::OPENING_HOUR,
                self::CLOSING_HOUR,
            ));
        }
        if ($slot->minutes() > self::MAX_MINUTES) {
            $violations[] = new Violation('slot.too_long', sprintf(
                'A booking cannot exceed %d minutes.',
                self::MAX_MINUTES,
            ));
        }
        if ($slot->start < $now) {
            $violations[] = new Violation('slot.in_past', 'A slot cannot start in the past.');
        }
        if ($slot->start > $now->modify(sprintf('+%d days', self::MAX_DAYS_AHEAD))) {
            $violations[] = new Violation('slot.too_far_ahead', sprintf(
                'Bookings open %d days ahead.',
                self::MAX_DAYS_AHEAD,
            ));
        }

        return $violations;
    }

    public static function isRefundable(Slot $slot, \DateTimeImmutable $now): bool
    {
        return $now <= $slot->start->modify(sprintf('-%d hours', self::FREE_CANCELLATION_HOURS));
    }

    public static function quotaExceeded(int $monthlyMinutes, int $usedMinutes, int $requestedMinutes): ?Violation
    {
        return ($usedMinutes + $requestedMinutes) > $monthlyMinutes
            ? new Violation('quota.exceeded', sprintf(
                'Company monthly quota of %d minutes would be exceeded.',
                $monthlyMinutes,
            ))
            : null;
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
