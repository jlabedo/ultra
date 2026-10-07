<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Slot;
use App\Booking\Domain\Violations;
use App\Booking\StateStore\Repository\BookingRepository;
use App\Booking\StateStore\State\BookingState;
use App\Booking\StateStore\State\CompanyState;
use App\Booking\StateStore\State\RoomState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\Time\ClockInterface;

final readonly class RescheduleBooking
{
    public function __construct(
        public string $bookingId,
        public string $memberId,
        public string $roomId,
        public Slot $slot,
    ) {}

    public function companyId(BookingState $booking): string
    {
        return $booking->companyId;
    }

    /**
     * @return list<object>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?BookingState $booking,
        ?RoomState $room,
        ?CompanyState $company,
        BookingRepository $bookings,
        ClockInterface $clock,
    ): array {
        $now = $clock->now();
        Violations::throwIfAny([
            null === $booking ? new Violation('booking.not_found', 'Booking does not exist.') : null,
            null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $booking->memberId !== $this->memberId
                ? new Violation('booking.not_owner', 'Only the member who booked can reschedule.')
                : null,
            null === $company ? new Violation('company.not_found', 'Company does not exist.') : null,
            BookingState::CANCELLED === $booking->status
                ? new Violation('booking.already_cancelled', 'Booking is cancelled.')
                : null,
        ]);

        Violations::throwIfAny([
            !BookingPolicy::isRefundable($booking->slot(), $now)
                ? new Violation(
                    'booking.too_late_to_reschedule',
                    'Bookings can only be moved until 24 hours before they start.',
                )
                : null,
            ...BookingPolicy::slotViolations($this->slot, $now),
            $bookings->hasOverlap($room, $this->slot, ignoringBookingId: $this->bookingId)
                ? new Violation('slot.unavailable', 'Room is already booked for this slot.')
                : null,
            BookingPolicy::quotaExceeded(
                $company->monthlyMinutes,
                $bookings->observeUsedMinutes($company, $this->slot->month(), ignoringBookingId: $this->bookingId),
                $this->slot->minutes(),
            ),
        ]);

        return [$booking->movedTo($this->roomId, $this->slot)];
    }
}
