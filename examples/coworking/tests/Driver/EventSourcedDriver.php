<?php

declare(strict_types=1);

namespace App\Tests\Driver;

use App\Booking\Domain\BookingIds;
use App\Booking\Domain\Slot;
use App\Booking\EventSourced\State\BookingState;
use App\Booking\EventSourced\State\CompanyMonthUsageState;
use App\Booking\EventSourced\State\MemberState;
use App\Booking\EventSourced\State\RoomDayScheduleState;
use App\Booking\EventSourced\Write;
use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\EventStore\DomainIdentifier;
use Ultra\MessageHandling\MediatorInterface;

final readonly class EventSourcedDriver implements BookingDriver
{
    public function __construct(
        private MediatorInterface $mediator,
        private EventSourcedStateStore $states,
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
        $this->mediator->send(new Write\BookRoom($bookingId, $roomId, $memberId, $this->companyOf($memberId), $slot));
    }

    public function bookWeekly(string $seriesId, string $roomId, string $memberId, Slot $firstSlot, int $weeks): void
    {
        $this->mediator->send(new Write\BookWeekly($seriesId, $roomId, $memberId, $this->companyOf($memberId), $firstSlot, $weeks));
    }

    public function cancel(string $bookingId, string $memberId): void
    {
        $this->mediator->send(new Write\CancelBooking($bookingId, $memberId));
    }

    public function reschedule(string $bookingId, string $memberId, string $roomId, Slot $slot): void
    {
        $this->mediator->send(new Write\RescheduleBooking($bookingId, $memberId, $this->companyOf($memberId), $roomId, $slot));
    }

    public function closeRoomForDay(string $roomId, string $day): void
    {
        $this->mediator->send(new Write\CloseRoomForDay($roomId, $day));
    }

    public function usedMinutes(string $companyId, string $month): int
    {
        return $this->states->load(
            CompanyMonthUsageState::class,
            new DomainIdentifier('companyMonth', BookingIds::companyMonth($companyId, $month)),
        )->usedMinutes();
    }

    public function bookingsOnDay(string $roomId, string $day): array
    {
        $bookingIds = array_keys($this->states->load(
            RoomDayScheduleState::class,
            new DomainIdentifier('roomDay', BookingIds::roomDay($roomId, $day)),
        )->bookings);
        sort($bookingIds);

        return $bookingIds;
    }

    public function bookingStatus(string $bookingId): ?string
    {
        return $this->states->load(BookingState::class, new DomainIdentifier('booking', $bookingId))->status;
    }

    /**
     * What a controller has to do before sending BookRoom: an extra read, outside the decision's consistency
     * boundary, only to compute which boundary to load.
     */
    private function companyOf(string $memberId): string
    {
        return $this->states->load(MemberState::class, new DomainIdentifier('member', $memberId))->companyId;
    }
}
