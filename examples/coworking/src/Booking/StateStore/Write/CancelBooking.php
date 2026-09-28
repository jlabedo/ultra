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
 * The schedule row is identified by the booking itself: it is a dependent parameter, loaded once the booking is.
 *
 * The usage row is only needed when the cancellation is refunded, which depends on the clock. It is loaded by hand
 * with an explicit expect(), so a late cancellation does not conflict with concurrent changes to the company's usage.
 */
final readonly class CancelBooking
{
    public function __construct(
        public string $bookingId,
        public string $memberId,
    ) {}

    public function roomDayId(BookingState $booking): string
    {
        return $booking->roomDayId();
    }

    /**
     * @return list<object>
     */
    #[SelfHandler]
    #[DecisionHandler]
    public function decide(
        ?BookingState $booking,
        ?RoomDayScheduleState $schedule,
        StateStore $stateStore,
        ClockInterface $clock,
    ): array {
        Violations::throwIfAny([
            null === $booking ? new Violation('booking.not_found', 'Booking does not exist.') : null,
        ]);
        Violations::throwIfAny([
            $booking->memberId !== $this->memberId ? new Violation('booking.not_owner', 'Only the member who booked can cancel.') : null,
            BookingState::CANCELLED === $booking->status ? new Violation('booking.already_cancelled', 'Booking is already cancelled.') : null,
        ]);

        $changes = [$booking->cancelled()];
        if (null !== $schedule) {
            $changes[] = $schedule->without($this->bookingId);
        }

        if (BookingPolicy::isRefundable($booking->slot(), $clock->now())) {
            $usage = $stateStore->load(CompanyMonthUsageState::class, $booking->companyMonthId());
            $stateStore->expect($usage);
            $changes[] = $usage->refunding($this->bookingId);
        }

        return $changes;
    }
}
