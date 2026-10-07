<?php

declare(strict_types=1);

namespace App\Booking\EventSourced\Write;

use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Violations;
use App\Booking\EventSourced\Event\BookingCancelled;
use App\Booking\EventSourced\State\BookingState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\Time\ClockInterface;

final readonly class CancelBooking
{
    public function __construct(
        public string $bookingId,
        public string $memberId,
    ) {}

    #[SelfHandler]
    #[DecisionHandler]
    public function decide(BookingState $booking, ClockInterface $clock): BookingCancelled
    {
        Violations::throwIfAny([
            !$booking->exists() ? new Violation('booking.not_found', 'Booking does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $booking->memberId !== $this->memberId
                ? new Violation('booking.not_owner', 'Only the member who booked can cancel.')
                : null,
            BookingState::CANCELLED === $booking->status
                ? new Violation('booking.already_cancelled', 'Booking is already cancelled.')
                : null,
        ]);

        return new BookingCancelled(
            $this->bookingId,
            $booking->roomId,
            $booking->memberId,
            $booking->companyId,
            $booking->slot->start,
            $booking->slot->end,
            BookingPolicy::isRefundable($booking->slot, $clock->now()),
            BookingCancelled::BY_MEMBER,
        );
    }
}
