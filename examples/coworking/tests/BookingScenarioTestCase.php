<?php

declare(strict_types=1);

namespace App\Tests;

use App\Booking\Domain\Slot;
use App\Tests\Driver\BookingDriver;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;

/**
 * Behavior every flavor must satisfy. The clock is frozen at 2026-09-01 09:00 UTC (a Tuesday).
 */
abstract class BookingScenarioTestCase extends CoworkingKernelTestCase
{
    abstract protected function driver(): BookingDriver;

    public function test_member_emails_are_unique_ignoring_case(): void
    {
        $this->driver()->registerCompany('acme', 'Acme', 600);
        $this->driver()->registerMember('ada', 'acme', 'Ada@Example.test');

        $this->assertViolation('member.email_taken', fn () => $this->driver()->registerMember('grace', 'acme', ' ada@example.test'));
    }

    public function test_overlapping_bookings_are_rejected_and_adjacent_ones_accepted(): void
    {
        $this->givenAcmeWithAdaAndGrace();

        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        $this->assertViolation('slot.unavailable', fn () => $this->driver()->book('b2', 'blue', 'grace', Slot::of('2026-09-10 10:30', '2026-09-10 11:30')));
        $this->driver()->book('b3', 'blue', 'grace', Slot::of('2026-09-10 11:00', '2026-09-10 12:00'));
        $this->driver()->book('b4', 'red', 'grace', Slot::of('2026-09-10 10:30', '2026-09-10 11:30'));

        self::assertSame(['b1', 'b3'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
    }

    public function test_slot_rules_are_enforced(): void
    {
        $this->givenAcmeWithAdaAndGrace();

        $this->assertViolation('slot.off_grid', fn () => $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:15', '2026-09-10 11:00')));
        $this->assertViolation('slot.outside_opening_hours', fn () => $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 19:00', '2026-09-10 21:00')));
        $this->assertViolation('slot.too_long', fn () => $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 08:00', '2026-09-10 13:00')));
        $this->assertViolation('slot.in_past', fn () => $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-08-31 10:00', '2026-08-31 11:00')));
        $this->assertViolation('room.not_found', fn () => $this->driver()->book('b1', 'green', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00')));
        $this->assertViolation('member.not_found', fn () => $this->driver()->book('b1', 'blue', 'nobody', Slot::of('2026-09-10 10:00', '2026-09-10 11:00')));
    }

    public function test_the_quota_is_pooled_per_company_and_month(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 120);

        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        $this->driver()->book('b2', 'red', 'grace', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        $this->assertViolation('quota.exceeded', fn () => $this->driver()->book('b3', 'blue', 'ada', Slot::of('2026-09-11 10:00', '2026-09-11 10:30')));
        $this->driver()->book('b4', 'blue', 'ada', Slot::of('2026-10-01 10:00', '2026-10-01 12:00'));

        self::assertSame(120, $this->driver()->usedMinutes('acme', '2026-09'));
        self::assertSame(120, $this->driver()->usedMinutes('acme', '2026-10'));

        $this->driver()->changeCompanyPlan('acme', 150);
        $this->driver()->book('b5', 'blue', 'ada', Slot::of('2026-09-11 10:00', '2026-09-11 10:30'));
        self::assertSame(150, $this->driver()->usedMinutes('acme', '2026-09'));
    }

    public function test_early_cancellation_is_refunded_and_frees_the_slot(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));

        $this->assertViolation('booking.not_owner', fn () => $this->driver()->cancel('b1', 'grace'));
        $this->driver()->cancel('b1', 'ada');
        $this->assertViolation('booking.already_cancelled', fn () => $this->driver()->cancel('b1', 'ada'));

        self::assertSame('cancelled', $this->driver()->bookingStatus('b1'));
        self::assertSame(0, $this->driver()->usedMinutes('acme', '2026-09'));
        $this->driver()->book('b2', 'blue', 'grace', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        self::assertSame(['b2'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
    }

    public function test_late_cancellation_frees_the_slot_but_is_still_charged(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-01 15:00', '2026-09-01 16:00'));

        $this->driver()->cancel('b1', 'ada');

        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
        self::assertSame([], $this->driver()->bookingsOnDay('blue', '2026-09-01'));
    }

    public function test_rescheduling_moves_the_slot_and_the_charge_across_days_rooms_and_months(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-30 10:00', '2026-09-30 12:00'));

        $this->driver()->reschedule('b1', 'ada', 'red', Slot::of('2026-10-01 10:00', '2026-10-01 12:00'));

        self::assertSame([], $this->driver()->bookingsOnDay('blue', '2026-09-30'));
        self::assertSame(['b1'], $this->driver()->bookingsOnDay('red', '2026-10-01'));
        self::assertSame(0, $this->driver()->usedMinutes('acme', '2026-09'));
        self::assertSame(120, $this->driver()->usedMinutes('acme', '2026-10'));
        self::assertSame('confirmed', $this->driver()->bookingStatus('b1'));
    }

    public function test_rescheduling_within_the_same_day_ignores_the_booking_itself(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 120);
        $this->driver()->registerCompany('globex', 'Globex', 600);
        $this->driver()->registerMember('hank', 'globex', 'hank@example.test');
        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 12:00'));
        $this->driver()->book('b2', 'blue', 'hank', Slot::of('2026-09-10 14:00', '2026-09-10 15:00'));

        $this->driver()->reschedule('b1', 'ada', 'blue', Slot::of('2026-09-10 10:30', '2026-09-10 12:30'));
        $this->assertViolation('slot.unavailable', fn () => $this->driver()->reschedule('b1', 'ada', 'blue', Slot::of('2026-09-10 13:30', '2026-09-10 14:30')));

        self::assertSame(['b1', 'b2'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame(120, $this->driver()->usedMinutes('acme', '2026-09'));
    }

    public function test_late_bookings_cannot_be_rescheduled(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-02 08:00', '2026-09-02 09:00'));

        $this->assertViolation('booking.too_late_to_reschedule', fn () => $this->driver()->reschedule('b1', 'ada', 'blue', Slot::of('2026-09-03 08:00', '2026-09-03 09:00')));
    }

    public function test_a_weekly_series_is_all_or_nothing(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        $this->driver()->book('blocker', 'blue', 'grace', Slot::of('2026-09-29 10:00', '2026-09-29 11:00'));

        $this->assertViolation('slot.unavailable', fn () => $this->driver()->bookWeekly('s1', 'blue', 'ada', Slot::of('2026-09-15 10:00', '2026-09-15 11:00'), 4));

        self::assertSame([], $this->driver()->bookingsOnDay('blue', '2026-09-15'));
        self::assertSame([], $this->driver()->bookingsOnDay('blue', '2026-09-22'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));

        $this->driver()->bookWeekly('s2', 'red', 'ada', Slot::of('2026-09-15 10:00', '2026-09-15 11:00'), 4);

        self::assertSame(['s2#0'], $this->driver()->bookingsOnDay('red', '2026-09-15'));
        self::assertSame(['s2#3'], $this->driver()->bookingsOnDay('red', '2026-10-06'));
        self::assertSame(60 + 180, $this->driver()->usedMinutes('acme', '2026-09'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-10'));
    }

    public function test_a_weekly_series_respects_the_quota_including_its_own_occurrences(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 120);

        $this->assertViolation('quota.exceeded', fn () => $this->driver()->bookWeekly('s1', 'blue', 'ada', Slot::of('2026-09-08 10:00', '2026-09-08 11:00'), 3));

        self::assertSame(0, $this->driver()->usedMinutes('acme', '2026-09'));
    }

    public function test_a_weekly_series_checks_each_months_quota_separately(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 120);
        $this->driver()->bookWeekly('s1', 'blue', 'ada', Slot::of('2026-09-22 10:00', '2026-09-22 11:00'), 3);

        self::assertSame(120, $this->driver()->usedMinutes('acme', '2026-09'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-10'));
        self::assertSame(['s1#2'], $this->driver()->bookingsOnDay('blue', '2026-10-06'));
    }

    public function test_a_later_months_quota_failure_discards_the_entire_weekly_series(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 120);
        $this->driver()->book('existing', 'red', 'grace', Slot::of('2026-10-08 10:00', '2026-10-08 11:00'));
        $this->assertViolation('quota.exceeded', fn () => $this->driver()->bookWeekly('s1', 'blue', 'ada', Slot::of('2026-09-22 10:00', '2026-09-22 11:00'), 4));

        self::assertSame(0, $this->driver()->usedMinutes('acme', '2026-09'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-10'));
        self::assertSame([], $this->driver()->bookingsOnDay('blue', '2026-09-22'));
        self::assertSame([], $this->driver()->bookingsOnDay('blue', '2026-10-13'));
    }

    public function test_closing_a_room_for_a_day_cancels_and_refunds_every_booking_of_that_day(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        $this->driver()->registerCompany('globex', 'Globex', 600);
        $this->driver()->registerMember('hank', 'globex', 'hank@example.test');
        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        $this->driver()->book('b2', 'blue', 'hank', Slot::of('2026-09-10 14:00', '2026-09-10 16:00'));
        $this->driver()->book('b3', 'blue', 'ada', Slot::of('2026-09-11 10:00', '2026-09-11 11:00'));

        $this->driver()->closeRoomForDay('blue', '2026-09-10');

        self::assertSame([], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame('cancelled', $this->driver()->bookingStatus('b1'));
        self::assertSame('cancelled', $this->driver()->bookingStatus('b2'));
        self::assertSame('confirmed', $this->driver()->bookingStatus('b3'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
        self::assertSame(0, $this->driver()->usedMinutes('globex', '2026-09'));
    }

    protected function givenAcmeWithAdaAndGrace(int $monthlyMinutes = 600): void
    {
        $this->driver()->registerCompany('acme', 'Acme', $monthlyMinutes);
        $this->driver()->registerMember('ada', 'acme', 'ada@example.test');
        $this->driver()->registerMember('grace', 'acme', 'grace@example.test');
        $this->driver()->openRoom('blue', 'Blue room');
        $this->driver()->openRoom('red', 'Red room');
    }

    protected function assertViolation(string $code, callable $action): void
    {
        try {
            $action();
        } catch (InvariantViolation $violation) {
            self::assertContains($code, array_map(static fn ($v): string => $v->code, $violation->violations), $violation->getMessage());

            return;
        }

        self::fail(sprintf('Expected violation "%s".', $code));
    }
}
