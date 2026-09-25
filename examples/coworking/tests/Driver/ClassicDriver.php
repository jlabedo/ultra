<?php

declare(strict_types=1);

namespace App\Tests\Driver;

use App\Booking\Classic\BookingService;
use App\Booking\Classic\Entity\Booking;
use App\Booking\Classic\Entity\Company;
use App\Booking\Classic\Entity\Room;
use App\Booking\Classic\Repository\BookingRepository;
use App\Booking\Domain\Slot;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ClassicDriver implements BookingDriver
{
    public function __construct(
        private BookingService $service,
        private BookingRepository $bookings,
        private EntityManagerInterface $entityManager,
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
        $company = $this->entityManager->find(Company::class, $companyId);

        return null === $company ? 0 : $this->bookings->usedMinutes($company, $month);
    }

    public function bookingsOnDay(string $roomId, string $day): array
    {
        $room = $this->entityManager->find(Room::class, $roomId);

        return null === $room ? [] : array_map(static fn (Booking $booking): string => $booking->id, $this->bookings->confirmedOnDay($room, $day));
    }

    public function bookingStatus(string $bookingId): ?string
    {
        $this->entityManager->clear();

        return $this->entityManager->find(Booking::class, $bookingId)?->status;
    }
}
