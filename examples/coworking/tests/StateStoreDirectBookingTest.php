<?php

declare(strict_types=1);

namespace App\Tests;

use App\Booking\Domain\Slot;
use App\Booking\StateStoreDirect\BookingService;
use App\Booking\StateStoreDirect\Repository\BookingRepository;
use App\Tests\Driver\BookingDriver;
use App\Tests\Driver\StateStoreDirectDriver;
use Doctrine\DBAL\Connection;
use Ultra\StatefulMessageHandling\Exception\InvariantViolation;
use Ultra\StateStore\Exception\PendingQueryChangesException;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/**
 * @internal
 */
final class StateStoreDirectBookingTest extends BookingScenarioTestCase
{
    public function test_a_second_booking_in_the_same_scope_is_rejected_before_any_transaction_starts(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 60);
        $connection = self::getContainer()->get(Connection::class);
        $this->assertPendingQueryChanges('company_usage', function () use ($connection): void {
            self::getContainer()
                ->get(UnitOfWorkRunner::class)
                ->run(function () use ($connection): void {
                    $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
                    self::assertFalse($connection->isTransactionActive());
                    $this->driver()->book('b2', 'red', 'grace', Slot::of('2026-09-11 10:00', '2026-09-11 11:00'));
                });
        });
        self::assertNull($this->driver()->bookingStatus('b1'));
        self::assertSame(0, $this->driver()->usedMinutes('acme', '2026-09'));
        $this->driver()->book('after-failure', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
    }

    public function test_a_booking_after_a_buffered_cancellation_is_rejected_and_the_cancellation_is_discarded(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 60);
        $slot = Slot::of('2026-09-10 10:00', '2026-09-10 11:00');
        $this->driver()->book('b1', 'blue', 'ada', $slot);
        $this->assertPendingQueryChanges('room_bookings', function () use ($slot): void {
            self::getContainer()
                ->get(UnitOfWorkRunner::class)
                ->run(function () use ($slot): void {
                    $this->driver()->cancel('b1', 'ada');
                    $this->driver()->book('b2', 'blue', 'grace', $slot);
                });
        });
        self::assertSame('confirmed', $this->driver()->bookingStatus('b1'));
        self::assertSame(['b1'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
        // Sequential actions remain valid when each decision has its own flush.
        $this->driver()->cancel('b1', 'ada');
        $this->driver()->book('b2', 'blue', 'grace', $slot);
        self::assertSame(['b2'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
    }

    public function test_a_second_buffered_move_is_rejected_and_the_first_move_is_discarded(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 120);
        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        $this->assertPendingQueryChanges('room_bookings', function (): void {
            self::getContainer()
                ->get(UnitOfWorkRunner::class)
                ->run(function (): void {
                    $this->driver()->reschedule('b1', 'ada', 'red', Slot::of('2026-10-01 10:00', '2026-10-01 11:00'));
                    $this->driver()->reschedule('b1', 'ada', 'blue', Slot::of('2026-09-11 10:00', '2026-09-11 11:00'));
                });
        });
        self::assertSame(['b1'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame([], $this->driver()->bookingsOnDay('red', '2026-10-01'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
        self::assertSame(0, $this->driver()->usedMinutes('acme', '2026-10'));
    }

    public function test_related_states_can_be_created_and_booked_in_one_flush(): void
    {
        self::getContainer()
            ->get(UnitOfWorkRunner::class)
            ->run(function (): void {
                $this->driver()->registerCompany('acme', 'Acme', 60);
                $this->driver()->registerMember('ada', 'acme', 'ada@example.test');
                $this->driver()->openRoom('blue', 'Blue room');
                $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
            });
        self::assertSame(['b1'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
    }

    public function test_duplicate_identifiers_keep_their_domain_violations(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        $this->assertViolation('company.already_registered', fn() => $this->driver()->registerCompany(
            'acme',
            'Another company',
            1,
        ));
        $this->assertViolation('member.already_registered', fn() => $this->driver()->registerMember(
            'ada',
            'acme',
            'other@example.test',
        ));
        $this->assertViolation('room.already_opened', fn() => $this->driver()->openRoom('blue', 'Another room'));
        $slot = Slot::of('2026-09-10 10:00', '2026-09-10 11:00');
        $this->driver()->book('b1', 'blue', 'ada', $slot);
        $this->assertViolation('booking.already_exists', fn() => $this->driver()->book('b1', 'red', 'grace', $slot));
        self::assertSame(['b1'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame([], $this->driver()->bookingsOnDay('red', '2026-09-10'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
    }

    public function test_missing_states_keep_their_domain_violations(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        $slot = Slot::of('2026-09-10 10:00', '2026-09-10 11:00');
        $this->assertViolation('company.not_found', fn() => $this->driver()->changeCompanyPlan('missing', 120));
        $this->assertViolation('company.not_found', fn() => $this->driver()->registerMember(
            'new-member',
            'missing',
            'new@example.test',
        ));
        $this->assertViolation('booking.not_found', fn() => $this->driver()->cancel('missing', 'ada'));
        $this->assertViolation('booking.not_found', fn() => $this->driver()->reschedule(
            'missing',
            'ada',
            'red',
            $slot,
        ));
        $this->assertViolation('member.not_found', fn() => $this->driver()->book('b1', 'blue', 'missing', $slot));
        $this->assertViolation('room.not_found', fn() => $this->driver()->book('b1', 'missing', 'ada', $slot));
        $this->driver()->book('b1', 'blue', 'ada', $slot);
        $this->assertViolation('room.not_found', fn() => $this->driver()->reschedule('b1', 'ada', 'missing', $slot));
        self::assertSame(['b1'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
    }

    public function test_required_company_and_booking_report_the_same_messages_as_doctrine(): void
    {
        foreach ([
            [fn() => $this->driver()->changeCompanyPlan('missing', 120), 'company.not_found: Company does not exist.'],
            [fn() => $this->driver()->cancel('missing', 'ada'), 'booking.not_found: Booking does not exist.'],
        ] as [$action, $message]) {
            try {
                $action();
            } catch (InvariantViolation $violation) {
                self::assertSame($message, $violation->getMessage());

                continue;
            }

            self::fail('Expected violation: ' . $message);
        }
    }

    public function test_member_registration_reports_both_duplicate_identifier_and_email(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        try {
            $this->driver()->registerMember('ada', 'acme', 'grace@example.test');
        } catch (InvariantViolation $violation) {
            self::assertSame(
                ['member.already_registered', 'member.email_taken'],
                array_map(static fn($v): string => $v->code, $violation->violations),
            );
            // A failed decision must leave no expectations that interfere with the next registration.
            $this->driver()->registerMember('new-member', 'acme', 'new@example.test');

            return;
        }

        self::fail('Expected both member registration violations.');
    }

    private function assertPendingQueryChanges(string $guardName, callable $action): void
    {
        try {
            $action();
        } catch (PendingQueryChangesException $exception) {
            self::assertSame($guardName, $exception->guardName);

            return;
        }
        self::fail('Expected a pending-write query rejection for ' . $guardName . '.');
    }

    protected function driver(): BookingDriver
    {
        return new StateStoreDirectDriver(
            self::getContainer()->get(BookingService::class),
            self::getContainer()->get(BookingRepository::class),
            self::getContainer()->get(StateStore::class),
        );
    }
}
