<?php

declare(strict_types=1);

namespace App\Tests\Driver;

use App\Booking\StateStoreDirect\BookingService;
use App\Booking\StateStoreDirect\State\Booking;
use App\Booking\StateStoreDirect\State\Company;
use App\Booking\StateStoreDirect\State\Room;
use App\Booking\StateStoreDirect\Repository\BookingRepository;
use App\Booking\Domain\Slot;
use Ultra\StateStore\StateStore;

final readonly class StateStoreDirectDriver implements BookingDriver
{
    public function __construct(
        private BookingService $service,
        private BookingRepository $bookings,
        private StateStore $stateStore,
    ) {}

    public function registerCompany(string $companyId, string $name, int $monthlyMinutes): void
    {
        $this->service->registerCompany($companyId, $name, $monthlyMinutes);
    }

    public function changeCompanyPlan(string $companyId, int $monthlyMinutes): void
    {
        $this->service->changeCompanyPlan($companyId, $monthlyMinutes);
    }

    public function registerMember(string $memberId, string $companyId, string $email): void
    {
        $this->service->registerMember($memberId, $companyId, $email);
    }

    public function openRoom(string $roomId, string $name): void
    {
        $this->service->openRoom($roomId, $name);
    }

    public function book(string $bookingId, string $roomId, string $memberId, Slot $slot): void
    {
        $this->service->book($bookingId, $roomId, $memberId, $slot);
    }

    public function bookWeekly(string $seriesId, string $roomId, string $memberId, Slot $firstSlot, int $weeks): void
    {
        $this->service->bookWeekly($seriesId, $roomId, $memberId, $firstSlot, $weeks);
    }

    public function cancel(string $bookingId, string $memberId): void
    {
        $this->service->cancel($bookingId, $memberId);
    }

    public function reschedule(string $bookingId, string $memberId, string $roomId, Slot $slot): void
    {
        $this->service->reschedule($bookingId, $memberId, $roomId, $slot);
    }

    public function closeRoomForDay(string $roomId, string $day): void
    {
        $this->service->closeRoomForDay($roomId, $day);
    }

    public function usedMinutes(string $companyId, string $month): int
    {
        $company = $this->stateStore->load(Company::class, $companyId);

        return null === $company ? 0 : $this->bookings->usedMinutes($company, $month);
    }

    public function bookingsOnDay(string $roomId, string $day): array
    {
        $room = $this->stateStore->load(Room::class, $roomId);

        return null === $room ? [] : $this->bookings->bookingIdsOnDay($room, $day);
    }

    public function bookingStatus(string $bookingId): ?string
    {

        return $this->stateStore->load(Booking::class, $bookingId)?->status;
    }
}
