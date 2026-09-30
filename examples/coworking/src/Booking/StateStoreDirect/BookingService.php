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
            $this->expectNew(Company::class, $companyId, 'company.already_registered');
            $this->states->save(new Company($companyId, $name, $monthlyMinutes));
        });
    }

    public function changeCompanyPlan(string $companyId, int $monthlyMinutes): void
    {
        $this->unitOfWork->run(function () use ($companyId, $monthlyMinutes): void {
            $company = $this->state(Company::class, $companyId, 'company.not_found');
            $this->states->lock($company);
            $this->states->save($company->withMonthlyMinutes($monthlyMinutes));
        });
    }

    public function registerMember(string $memberId, string $companyId, string $email): void
    {
        $email = BookingPolicy::normalizeEmail($email);
        $this->unitOfWork->run(function () use ($memberId, $companyId, $email): void {
            $this->expectNew(Member::class, $memberId, 'member.already_registered');
            Violations::throwIfAny([
                $this->states->observeQuery(fn (): bool => $this->bookings->emailTaken($email), 'member email '.$email) ? new Violation('member.email_taken', 'Email is already used by another member.') : null,
            ]);
            $this->state(Company::class, $companyId, 'company.not_found');
            // As with Doctrine, a concurrent duplicate email is also protected by a unique index.
            $this->states->save(new Member($memberId, $companyId, $email));
        });
    }

    public function openRoom(string $roomId, string $name): void
    {
        $this->unitOfWork->run(function () use ($roomId, $name): void {
            $this->expectNew(Room::class, $roomId, 'room.already_opened');
            $this->states->save(new Room($roomId, $name));
        });
    }

    public function book(string $bookingId, string $roomId, string $memberId, Slot $slot): void
    {
        $this->unitOfWork->run(fn () => $this->bookOne($bookingId, $roomId, $memberId, $slot));
    }

    public function bookWeekly(string $seriesId, string $roomId, string $memberId, Slot $firstSlot, int $weeks): void
    {
        $this->unitOfWork->run(function () use ($seriesId, $roomId, $memberId, $firstSlot, $weeks): void {
            for ($week = 0; $week < $weeks; ++$week) {
                $this->bookOne(BookingIds::occurrence($seriesId, $week), $roomId, $memberId, $firstSlot->shiftedByWeeks($week));
            }
        });
    }

    public function cancel(string $bookingId, string $memberId): void
    {
        $this->unitOfWork->run(function () use ($bookingId, $memberId): void {
            $booking = $this->state(Booking::class, $bookingId, 'booking.not_found');
            $this->lockBookingGuards($booking);
            Violations::throwIfAny([
                $booking->memberId !== $memberId ? new Violation('booking.not_owner', 'Only the member who booked can cancel.') : null,
                Booking::CANCELLED === $booking->status ? new Violation('booking.already_cancelled', 'Booking is already cancelled.') : null,
            ]);
            $this->bookings->save($booking->cancelled(BookingPolicy::isRefundable($booking->slot(), $this->clock->now())), $booking);
        });
    }

    public function reschedule(string $bookingId, string $memberId, string $roomId, Slot $slot): void
    {
        $this->unitOfWork->run(function () use ($bookingId, $memberId, $roomId, $slot): void {
            $now = $this->clock->now();
            $booking = $this->state(Booking::class, $bookingId, 'booking.not_found');
            $company = $this->lockBookingGuards($booking);
            $room = $this->state(Room::class, $roomId, 'room.not_found');
            $this->states->lock($room);
            Violations::throwIfAny([
                $booking->memberId !== $memberId ? new Violation('booking.not_owner', 'Only the member who booked can reschedule.') : null,
                Booking::CANCELLED === $booking->status ? new Violation('booking.already_cancelled', 'Booking is cancelled.') : null,
                !BookingPolicy::isRefundable($booking->slot(), $now) ? new Violation('booking.too_late_to_reschedule', 'Bookings can only be moved until 24 hours before they start.') : null,
                ...BookingPolicy::slotViolations($slot, $now),
                $this->bookings->hasOverlap($room, $slot, $bookingId) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
                BookingPolicy::quotaExceeded($company->monthlyMinutes, $this->bookings->observeUsedMinutes($company, $slot->month(), $bookingId), $slot->minutes()),
            ]);
            $this->bookings->save($booking->movedTo($roomId, $slot), $booking);
        });
    }

    public function closeRoomForDay(string $roomId, string $day): void
    {
        $this->unitOfWork->run(function () use ($roomId, $day): void {
            $room = $this->states->load(Room::class, $roomId);
            if (null === $room) {
                return;
            }
            $this->states->expect($room);
            $this->states->lock($room);
            foreach ($this->bookings->observeConfirmedOnDay($room, $day) as $booking) {
                $this->states->expect($booking);
                $this->lockBookingGuards($booking);
                $this->bookings->save($booking->cancelled(refunded: true), $booking);
            }
        });
    }

    private function bookOne(string $bookingId, string $roomId, string $memberId, Slot $slot): void
    {
        $this->expectNew(Booking::class, $bookingId, 'booking.already_exists');
        $member = $this->state(Member::class, $memberId, 'member.not_found');
        $room = $this->state(Room::class, $roomId, 'room.not_found');
        $company = $this->state(Company::class, $member->companyId, 'company.not_found');
        $this->states->lock($company);
        $this->states->lock($room);
        Violations::throwIfAny([
            ...BookingPolicy::slotViolations($slot, $this->clock->now()),
            $this->bookings->hasOverlap($room, $slot) ? new Violation('slot.unavailable', 'Room is already booked for this slot.') : null,
            BookingPolicy::quotaExceeded($company->monthlyMinutes, $this->bookings->observeUsedMinutes($company, $slot->month()), $slot->minutes()),
        ]);
        $this->bookings->save(new Booking($bookingId, $roomId, $memberId, $company->id, $slot->start, $slot->end));
    }

    private function lockBookingGuards(Booking $booking): Company
    {
        $company = $this->state(Company::class, $booking->companyId, 'company.not_found');
        $this->states->lock($company);
        $this->states->lock($this->state(Room::class, $booking->roomId, 'room.not_found'));

        return $company;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function state(string $class, string $id, string $violation): object
    {
        $state = $this->states->load($class, $id);
        Violations::throwIfAny([null === $state ? new Violation($violation, 'State does not exist.') : null]);
        $this->states->expect($state);

        return $state;
    }

    /** @param class-string $class */
    private function expectNew(string $class, string $id, string $violation): void
    {
        Violations::throwIfAny([null !== $this->states->load($class, $id) ? new Violation($violation, 'State already exists.') : null]);
        $this->states->expectMissing($class, $id);
    }
}
