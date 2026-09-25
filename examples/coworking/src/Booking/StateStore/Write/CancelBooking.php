<?php

declare(strict_types=1);

namespace App\Booking\StateStore\Write;

use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Violations;
use App\Booking\StateStore\State\BookingState;
use App\Booking\StateStore\State\CompanyMonthUsageState;
use App\Booking\StateStore\State\RoomDayScheduleState;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\StateStore\StateStore;
use Ultra\Time\ClockInterface;

/**
 * The schedule and usage rows to update are identified by the booking itself, so they cannot be decision
 * parameters: they are loaded by hand, and each one needs an explicit expect() to stay optimistic.
 */
final readonly class CancelBooking
{
    public function __construct(
        public string $bookingId,
        public string $memberId,
    ) {}

    /**
     * @return list<object>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(?BookingState $booking, StateStore $stateStore, ClockInterface $clock): array
    {
        Violations::throwIfAny([
            null === $booking ? new Violation('booking.not_found', 'Booking does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $booking->memberId !== $this->memberId ? new Violation('booking.not_owner', 'Only the member who booked can cancel.') : null,
            BookingState::CANCELLED === $booking->status ? new Violation('booking.already_cancelled', 'Booking is already cancelled.') : null,
        ]);

        $schedule = $stateStore->load(RoomDayScheduleState::class, $booking->roomDayId());
        $stateStore->expect($schedule);
        $changes = [$booking->cancelled(), $schedule->without($this->bookingId)];

        if (BookingPolicy::isRefundable($booking->slot(), $clock->now())) {
            $usage = $stateStore->load(CompanyMonthUsageState::class, $booking->companyMonthId());
            $stateStore->expect($usage);
            $changes[] = $usage->refunding($this->bookingId);
        }

        return $changes;
    }
}
