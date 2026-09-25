<?php

declare(strict_types=1);

namespace App\Tests\Driver;

use App\Booking\Domain\Slot;

/**
 * The same use cases, written in each persistence flavor. Scenario tests only talk to this interface.
 */
interface BookingDriver
{
    public function registerCompany(string $companyId, string $name, int $monthlyMinutes): void;

    public function changeCompanyPlan(string $companyId, int $monthlyMinutes): void;

    public function registerMember(string $memberId, string $companyId, string $email): void;

    public function openRoom(string $roomId, string $name): void;

    public function book(string $bookingId, string $roomId, string $memberId, Slot $slot): void;

    public function bookWeekly(string $seriesId, string $roomId, string $memberId, Slot $firstSlot, int $weeks): void;

    public function cancel(string $bookingId, string $memberId): void;

    public function reschedule(string $bookingId, string $memberId, string $roomId, Slot $slot): void;

    public function closeRoomForDay(string $roomId, string $day): void;

    public function usedMinutes(string $companyId, string $month): int;

    /**
     * @return list<string> booking ids, sorted
     */
    public function bookingsOnDay(string $roomId, string $day): array;

    public function bookingStatus(string $bookingId): ?string;
}
