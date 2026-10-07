<?php

declare(strict_types=1);

namespace App\Booking\StateStoreDirect;

use App\Booking\Domain\BookingIds;
use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Slot;
use App\Booking\Domain\Violations;
use App\Booking\StateStoreDirect\Repository\BookingRepository;
use App\Booking\StateStoreDirect\State\Booking;
use App\Booking\StateStoreDirect\State\Company;
use App\Booking\StateStoreDirect\State\Member;
use App\Booking\StateStoreDirect\State\Room;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\StateStore\StateStore;
use Ultra\Time\ClockInterface;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/** Decisions run outside a transaction; the runner flushes all expectations and writes in one transaction. */
final readonly class BookingService
{
    public function __construct(
        private StateStore $states,
        private BookingRepository $bookings,
        private UnitOfWorkRunner $unitOfWork,
        private ClockInterface $clock,
    ) {}

    public function registerCompany(string $companyId, string $name, int $monthlyMinutes): void
    {
        $this->unitOfWork->run(function () use ($companyId, $name, $monthlyMinutes): void {
            Violations::throwIfAny([
                null !== $this->states->observe(Company::class, $companyId)
                    ? new Violation('company.already_registered', 'Company already exists.')
                    : null,
            ]);
            $this->states->save(new Company($companyId, $name, $monthlyMinutes));
        });
    }

    public function changeCompanyPlan(string $companyId, int $monthlyMinutes): void
    {
        $this->unitOfWork->run(function () use ($companyId, $monthlyMinutes): void {
            $company = $this->company($companyId);
            $this->states->save($company->withMonthlyMinutes($monthlyMinutes));
        });
    }

    public function registerMember(string $memberId, string $companyId, string $email): void
    {
        $email = BookingPolicy::normalizeEmail($email);
        $this->unitOfWork->run(function () use ($memberId, $companyId, $email): void {
            Violations::throwIfAny([
                null !== $this->states->observe(Member::class, $memberId)
                    ? new Violation('member.already_registered', 'Member already exists.')
                    : null,
                $this->states->observeQuery(fn(): bool => $this->bookings->emailTaken($email), 'member email ' . $email)
                    ? new Violation('member.email_taken', 'Email is already used by another member.')
                    : null,
            ]);
            $this->company($companyId);
            // As with Doctrine, a concurrent duplicate email is also protected by a unique index.
            $this->states->save(new Member($memberId, $companyId, $email));
        });
    }

    public function openRoom(string $roomId, string $name): void
    {
        $this->unitOfWork->run(function () use ($roomId, $name): void {
            Violations::throwIfAny([
                null !== $this->states->observe(Room::class, $roomId)
                    ? new Violation('room.already_opened', 'Room already exists.')
                    : null,
            ]);
            $this->states->save(new Room($roomId, $name));
        });
    }

    public function book(string $bookingId, string $roomId, string $memberId, Slot $slot): void
    {
        $this->unitOfWork->run(fn() => $this->bookSlots($roomId, $memberId, [['id' => $bookingId, 'slot' => $slot]]));
    }

    public function bookWeekly(string $seriesId, string $roomId, string $memberId, Slot $firstSlot, int $weeks): void
    {
        $this->unitOfWork->run(function () use ($seriesId, $roomId, $memberId, $firstSlot, $weeks): void {
            $occurrences = [];
            for ($week = 0; $week < $weeks; ++$week) {
                $occurrences[] = [
                    'id' => BookingIds::occurrence($seriesId, $week),
                    'slot' => $firstSlot->shiftedByWeeks($week),
                ];
            }
            $this->bookSlots($roomId, $memberId, $occurrences);
        });
    }

    public function cancel(string $bookingId, string $memberId): void
    {
        $this->unitOfWork->run(function () use ($bookingId, $memberId): void {
            $booking = $this->booking($bookingId);
            Violations::throwIfAny([
                $booking->memberId !== $memberId
                    ? new Violation('booking.not_owner', 'Only the member who booked can cancel.')
                    : null,
                Booking::CANCELLED === $booking->status
                    ? new Violation('booking.already_cancelled', 'Booking is already cancelled.')
                    : null,
            ]);
            $this->states->save($booking->cancelled(BookingPolicy::isRefundable(
                $booking->slot(),
                $this->clock->now(),
            )));
        });
    }

    public function reschedule(string $bookingId, string $memberId, string $roomId, Slot $slot): void
    {
        $this->unitOfWork->run(function () use ($bookingId, $memberId, $roomId, $slot): void {
            $now = $this->clock->now();
            $booking = $this->booking($bookingId);
            $company = $this->company($booking->companyId);
            $room = $this->states->observe(Room::class, $roomId);
            Violations::throwIfAny([
                null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
            ]);
            Violations::throwIfAny([
                $booking->memberId !== $memberId
                    ? new Violation('booking.not_owner', 'Only the member who booked can reschedule.')
                    : null,
                Booking::CANCELLED === $booking->status
                    ? new Violation('booking.already_cancelled', 'Booking is cancelled.')
                    : null,
                !BookingPolicy::isRefundable($booking->slot(), $now)
                    ? new Violation(
                        'booking.too_late_to_reschedule',
                        'Bookings can only be moved until 24 hours before they start.',
                    )
                    : null,
                ...BookingPolicy::slotViolations($slot, $now),
                $this->bookings->hasOverlap($room, $slot, $bookingId)
                    ? new Violation('slot.unavailable', 'Room is already booked for this slot.')
                    : null,
                BookingPolicy::quotaExceeded(
                    $company->monthlyMinutes,
                    $this->bookings->observeUsedMinutes($company, $slot->month(), $bookingId),
                    $slot->minutes(),
                ),
            ]);
            $this->states->save($booking->movedTo($roomId, $slot));
        });
    }

    public function closeRoomForDay(string $roomId, string $day): void
    {
        $this->unitOfWork->run(function () use ($roomId, $day): void {
            $room = $this->states->observe(Room::class, $roomId);
            if (null === $room) {
                return;
            }
            foreach ($this->bookings->observeConfirmedOnDay($room, $day) as $booking) {
                $this->states->save($booking->cancelled(refunded: true));
            }
        });
    }

    /** @param list<array{id: string, slot: Slot}> $occurrences */
    private function bookSlots(string $roomId, string $memberId, array $occurrences): void
    {
        if ([] === $occurrences) {
            return;
        }
        foreach ($occurrences as ['id' => $bookingId]) {
            Violations::throwIfAny([
                null !== $this->states->observe(Booking::class, $bookingId)
                    ? new Violation('booking.already_exists', 'Booking already exists.')
                    : null,
            ]);
        }
        $member = $this->states->observe(Member::class, $memberId);
        Violations::throwIfAny([
            null === $member ? new Violation('member.not_found', 'Member does not exist.') : null,
        ]);
        $room = $this->states->observe(Room::class, $roomId);
        Violations::throwIfAny([
            null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
        ]);
        $company = $this->company($member->companyId);
        $requestedMinutes = [];
        foreach ($occurrences as ['slot' => $slot]) {
            Violations::throwIfAny([
                ...BookingPolicy::slotViolations($slot, $this->clock->now()),
                $this->bookings->hasOverlap($room, $slot)
                    ? new Violation('slot.unavailable', 'Room is already booked for this slot.')
                    : null,
            ]);
            $month = $slot->month();
            $requestedMinutes[$month] = ($requestedMinutes[$month] ?? 0) + $slot->minutes();
        }
        foreach ($requestedMinutes as $month => $minutes) {
            Violations::throwIfAny([
                BookingPolicy::quotaExceeded(
                    $company->monthlyMinutes,
                    $this->bookings->observeUsedMinutes($company, $month),
                    $minutes,
                ),
            ]);
        }
        foreach ($occurrences as ['id' => $bookingId, 'slot' => $slot]) {
            $this->states->save(new Booking($bookingId, $roomId, $memberId, $company->id, $slot->start, $slot->end));
        }
    }

    private function company(string $companyId): Company
    {
        $company = $this->states->observe(Company::class, $companyId);
        Violations::throwIfAny([
            null === $company ? new Violation('company.not_found', 'Company does not exist.') : null,
        ]);

        return $company;
    }

    private function booking(string $bookingId): Booking
    {
        $booking = $this->states->observe(Booking::class, $bookingId);
        Violations::throwIfAny([
            null === $booking ? new Violation('booking.not_found', 'Booking does not exist.') : null,
        ]);

        return $booking;
    }
}
