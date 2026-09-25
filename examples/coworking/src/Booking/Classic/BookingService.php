<?php

declare(strict_types=1);

namespace App\Booking\Classic;

use App\Booking\Classic\Entity\Booking;
use App\Booking\Classic\Entity\Company;
use App\Booking\Classic\Entity\Member;
use App\Booking\Classic\Entity\Room;
use App\Booking\Classic\Repository\BookingRepository;
use App\Booking\Domain\BookingIds;
use App\Booking\Domain\BookingPolicy;
use App\Booking\Domain\Slot;
use App\Booking\Domain\Violations;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\Time\ClockInterface;

/**
 * Classic application service: load entities, lock what must be serialized, query for set-based rules, flush.
 *
 * Concurrency is explicit: every use case locks the rows that guard its invariants (the company row for the
 * pooled quota, room rows for overlaps), always in the same order to avoid deadlocks.
 */
final readonly class BookingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingRepository $bookings,
        private ClockInterface $clock,
    ) {}

    public function registerCompany(string $companyId, string $name, int $monthlyMinutes): void
    {
        $this->transactional(function () use ($companyId, $name, $monthlyMinutes): void {
            Violations::throwIfAny([
                null !== $this->entityManager->find(Company::class, $companyId) ? new Violation('company.already_registered', 'Company already exists.') : null,
            ]);
            $this->entityManager->persist(new Company($companyId, $name, $monthlyMinutes));
        });
    }

    public function changeCompanyPlan(string $companyId, int $monthlyMinutes): void
    {
        $this->transactional(function () use ($companyId, $monthlyMinutes): void {
            $this->company($companyId)->changePlan($monthlyMinutes);
        });
    }

    public function registerMember(string $memberId, string $companyId, string $email): void
    {
        $email = BookingPolicy::normalizeEmail($email);
        $this->transactional(function () use ($memberId, $companyId, $email): void {
            Violations::throwIfAny([
                null !== $this->entityManager->find(Member::class, $memberId) ? new Violation('member.already_registered', 'Member already exists.') : null,
                null !== $this->entityManager->getRepository(Member::class)->findOneBy(['email' => $email]) ? new Violation('member.email_taken', 'Email is already used by another member.') : null,
            ]);
            // A concurrent registration with the same email is caught by the unique index, as a database error.
            $this->entityManager->persist(new Member($memberId, $this->company($companyId), $email));
        });
    }

    public function openRoom(string $roomId, string $name): void
    {
        $this->transactional(function () use ($roomId, $name): void {
            Violations::throwIfAny([
                null !== $this->entityManager->find(Room::class, $roomId) ? new Violation('room.already_opened', 'Room already exists.') : null,
            ]);
            $this->entityManager->persist(new Room($roomId, $name));
        });
    }

    public function book(string $bookingId, string $roomId, string $memberId, Slot $slot): void
    {
        $this->transactional(fn () => $this->bookOne($bookingId, $roomId, $memberId, $slot));
    }

    public function bookWeekly(string $seriesId, string $roomId, string $memberId, Slot $firstSlot, int $weeks): void
    {
        $this->transactional(function () use ($seriesId, $roomId, $memberId, $firstSlot, $weeks): void {
            for ($week = 0; $week < $weeks; ++$week) {
                $this->bookOne(BookingIds::occurrence($seriesId, $week), $roomId, $memberId, $firstSlot->shiftedByWeeks($week));
                // Later occurrences query the database, so earlier ones must be flushed first.
                $this->entityManager->flush();
            }
        });
    }

    public function cancel(string $bookingId, string $memberId): void
    {
        $this->transactional(function () use ($bookingId, $memberId): void {
            $booking = $this->booking($bookingId);
            Violations::throwIfAny([
                $booking->member->id !== $memberId ? new Violation('booking.not_owner', 'Only the member who booked can cancel.') : null,
                $booking->isCancelled() ? new Violation('booking.already_cancelled', 'Booking is already cancelled.') : null,
            ]);

            $booking->cancel(refunded: BookingPolicy::isRefundable($booking->slot(), $this->clock->now()));
        });
    }

    public function reschedule(string $bookingId, string $memberId, string $roomId, Slot $slot): void
    {
        $this->transactional(function () use ($bookingId, $memberId, $roomId, $slot): void {
            $now = $this->clock->now();
            $booking = $this->booking($bookingId);
            $this->lock($booking->company);
            $this->lockRooms($booking->room->id, $roomId);
            $room = $this->entityManager->find(Room::class, $roomId);

            Violations::throwIfAny([
                null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
                $booking->member->id !== $memberId ? new Violation('booking.not_owner', 'Only the member who booked can reschedule.') : null,
                $booking->isCancelled() ? new Violation('booking.already_cancelled', 'Booking is cancelled.') : null,
            ]);
            Violations::throwIfAny([
                !BookingPolicy::isRefundable($booking->slot(), $now) ? new Violation('booking.too_late_to_reschedule', 'Bookings can only be moved until 24 hours before they start.') : null,
                ...BookingPolicy::slotViolations($slot, $now),
                $this->bookings->hasOverlap($room, $slot, ignoringBookingId: $bookingId) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
                BookingPolicy::quotaExceeded($booking->company->monthlyMinutes, $this->bookings->usedMinutes($booking->company, $slot->month(), ignoringBookingId: $bookingId), $slot->minutes()),
            ]);

            $booking->moveTo($room, $slot);
        });
    }

    public function closeRoomForDay(string $roomId, string $day): void
    {
        $this->transactional(function () use ($roomId, $day): void {
            $room = $this->entityManager->find(Room::class, $roomId, LockMode::PESSIMISTIC_WRITE);
            if (null === $room) {
                return;
            }

            foreach ($this->bookings->confirmedOnDay($room, $day) as $booking) {
                $booking->cancel(refunded: true);
            }
        });
    }

    private function bookOne(string $bookingId, string $roomId, string $memberId, Slot $slot): void
    {
        Violations::throwIfAny([
            null !== $this->entityManager->find(Booking::class, $bookingId) ? new Violation('booking.already_exists', 'Booking already exists.') : null,
        ]);
        $member = $this->entityManager->find(Member::class, $memberId);
        $room = $this->entityManager->find(Room::class, $roomId);
        Violations::throwIfAny([
            null === $room ? new Violation('room.not_found', 'Room does not exist.') : null,
            null === $member ? new Violation('member.not_found', 'Member does not exist.') : null,
        ]);

        $company = $member->company;
        $this->lock($company);
        $this->lock($room);

        Violations::throwIfAny([
            ...BookingPolicy::slotViolations($slot, $this->clock->now()),
            $this->bookings->hasOverlap($room, $slot) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            BookingPolicy::quotaExceeded($company->monthlyMinutes, $this->bookings->usedMinutes($company, $slot->month()), $slot->minutes()),
        ]);

        $this->entityManager->persist(new Booking($bookingId, $room, $member, $company, $slot));
    }

    private function company(string $companyId): Company
    {
        $company = $this->entityManager->find(Company::class, $companyId);
        Violations::throwIfAny([
            null === $company ? new Violation('company.not_found', 'Company does not exist.') : null,
        ]);

        return $company;
    }

    private function booking(string $bookingId): Booking
    {
        $booking = $this->entityManager->find(Booking::class, $bookingId);
        Violations::throwIfAny([
            null === $booking ? new Violation('booking.not_found', 'Booking does not exist.') : null,
        ]);

        return $booking;
    }

    private function lock(object $entity): void
    {
        $this->entityManager->lock($entity, LockMode::PESSIMISTIC_WRITE);
    }

    private function lockRooms(string ...$roomIds): void
    {
        $roomIds = array_unique($roomIds);
        sort($roomIds);
        foreach ($roomIds as $roomId) {
            $room = $this->entityManager->find(Room::class, $roomId);
            if (null !== $room) {
                $this->lock($room);
            }
        }
    }

    /**
     * Connection-level transaction: an exception must not close the entity manager, since invariant violations
     * are expected outcomes, not crashes.
     */
    private function transactional(callable $work): void
    {
        try {
            $this->entityManager->getConnection()->transactional(function () use ($work): void {
                $work();
                $this->entityManager->flush();
            });
        } finally {
            $this->entityManager->clear();
        }
    }
}
