<?php

declare(strict_types=1);

namespace App\Tests\Driver;

use App\Booking\Domain\Slot;
use App\Booking\StateStore\State\BookingState;
use App\Booking\StateStore\Repository\BookingRepository;
use App\Booking\StateStore\Write;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\StateStore\StateStore;

final readonly class StateStoreDriver implements BookingDriver
{
    public function __construct(
        private MediatorInterface $mediator,
        private StateStore $states,
        private BookingRepository $bookings,
    ) {}

    public function registerCompany(string $companyId, string $name, int $monthlyMinutes): void
    {
        $this->mediator->send(new Write\RegisterCompany($companyId, $name, $monthlyMinutes));
    }

    public function changeCompanyPlan(string $companyId, int $monthlyMinutes): void
    {
        $this->mediator->send(new Write\ChangeCompanyPlan($companyId, $monthlyMinutes));
    }

    public function registerMember(string $memberId, string $companyId, string $email): void
    {
        $this->mediator->send(new Write\RegisterMember($memberId, $companyId, $email));
    }

    public function openRoom(string $roomId, string $name): void
    {
        $this->mediator->send(new Write\OpenRoom($roomId, $name));
    }

    public function book(string $bookingId, string $roomId, string $memberId, Slot $slot): void
    {
        $this->mediator->send(new Write\BookRoom($bookingId, $roomId, $memberId, $slot));
    }

    public function bookWeekly(string $seriesId, string $roomId, string $memberId, Slot $firstSlot, int $weeks): void
    {
        $this->mediator->send(new Write\BookWeekly($seriesId, $roomId, $memberId, $firstSlot, $weeks));
    }

    public function cancel(string $bookingId, string $memberId): void
    {
        $this->mediator->send(new Write\CancelBooking($bookingId, $memberId));
    }

    public function reschedule(string $bookingId, string $memberId, string $roomId, Slot $slot): void
    {
        $this->mediator->send(new Write\RescheduleBooking($bookingId, $memberId, $roomId, $slot));
    }

    public function closeRoomForDay(string $roomId, string $day): void
    {
        $this->mediator->send(new Write\CloseRoomForDay($roomId, $day));
    }

    public function usedMinutes(string $companyId, string $month): int
    {
        return $this->bookings->usedMinutes($companyId, $month);
    }

    public function bookingsOnDay(string $roomId, string $day): array
    {
        return $this->bookings->bookingIdsOnDay($roomId, $day);
    }

    public function bookingStatus(string $bookingId): ?string
    {
        return $this->states->load(BookingState::class, $bookingId)?->status;
    }
}
