<?php

declare(strict_types=1);

namespace App\Booking\StateStore;

use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Slot;
use App\Booking\Domain\Violations;
use App\Booking\StateStore\Repository\BookingRepository;
use App\Booking\StateStore\State\BookingState;
use App\Booking\StateStore\State\CompanyState;
use App\Booking\StateStore\State\MemberState;
use App\Booking\StateStore\State\RoomState;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\StateStore\StateStore;
use Ultra\Time\ClockInterface;

/** Validates the whole booking request before returning the states that the decision aspect will save. */
final readonly class BookingPlanner
{
    public function __construct(private BookingRepository $bookings, private StateStore $states, private ClockInterface $clock) {}

    /**
     * @param list<array{id: string, slot: Slot}> $occurrences A single slot or weekly series with distinct ids and disjoint slots.
     * @return list<BookingState>
     */
    public function plan(array $occurrences, ?RoomState $room, ?MemberState $member, ?CompanyState $company): array
    {
        if ([] === $occurrences) {
            return [];
        }
        foreach ($occurrences as ['id' => $id]) {
            Violations::throwIfAny([
                null !== $this->states->observe(BookingState::class, $id) ? new Violation('booking.already_exists', 'Booking already exists.') : null,
            ]);
        }
        Violations::throwIfAny([
            null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
            null === $member ? new Violation('member.not_found', 'Member does not exist.') : null,
        ]);
        Violations::throwIfAny([
            null === $company ? new Violation('company.not_found', 'Company does not exist.') : null,
        ]);
        $requestedMinutes = [];
        foreach ($occurrences as ['slot' => $slot]) {
            Violations::throwIfAny([
                ...BookingPolicy::slotViolations($slot, $this->clock->now()),
                $this->bookings->hasOverlap($room, $slot) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            ]);
            $month = $slot->month();
            $requestedMinutes[$month] = ($requestedMinutes[$month] ?? 0) + $slot->minutes();
        }
        foreach ($requestedMinutes as $month => $minutes) {
            Violations::throwIfAny([
                BookingPolicy::quotaExceeded($company->monthlyMinutes, $this->bookings->observeUsedMinutes($company, $month), $minutes),
            ]);
        }

        return array_map(
            static fn (array $occurrence): BookingState => new BookingState($occurrence['id'], $room->roomId, $member->memberId, $company->companyId, $occurrence['slot']->start, $occurrence['slot']->end),
            $occurrences,
        );
    }
}
