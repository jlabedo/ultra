<?php

declare(strict_types=1);

namespace App\Tests;

use App\Booking\StateStoreDirect\BookingService;
use App\Booking\StateStoreDirect\Repository\BookingRepository;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\UnitOfWorkRunner;
use Doctrine\DBAL\Connection;
use App\Booking\Domain\Slot;
use App\Tests\Driver\BookingDriver;
use App\Tests\Driver\StateStoreDirectDriver;

/**
 * @internal
 */
final class StateStoreDirectBookingTest extends BookingScenarioTestCase
{
    public function test_two_buffered_bookings_share_the_quota_before_any_transaction_starts(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 60);
        $connection = self::getContainer()->get(Connection::class);
        $this->assertViolation('quota.exceeded', function () use ($connection): void {
            self::getContainer()->get(UnitOfWorkRunner::class)->run(function () use ($connection): void {
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

    public function test_a_buffered_cancellation_frees_its_slot_and_quota_for_the_next_decision(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 60);
        $slot = Slot::of('2026-09-10 10:00', '2026-09-10 11:00');
        $this->driver()->book('b1', 'blue', 'ada', $slot);
        self::getContainer()->get(UnitOfWorkRunner::class)->run(function () use ($slot): void {
            $this->driver()->cancel('b1', 'ada');
            $this->driver()->book('b2', 'blue', 'grace', $slot);
        });
        self::assertSame('cancelled', $this->driver()->bookingStatus('b1'));
        self::assertSame(['b2'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
    }

    public function test_multiple_buffered_moves_keep_the_original_charge_only_once(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 120);
        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        self::getContainer()->get(UnitOfWorkRunner::class)->run(function (): void {
            $this->driver()->reschedule('b1', 'ada', 'red', Slot::of('2026-10-01 10:00', '2026-10-01 11:00'));
            $this->driver()->reschedule('b1', 'ada', 'blue', Slot::of('2026-09-11 10:00', '2026-09-11 11:00'));
            $this->driver()->book('b2', 'red', 'grace', Slot::of('2026-09-11 10:00', '2026-09-11 11:00'));
        });
        self::assertSame(120, $this->driver()->usedMinutes('acme', '2026-09'));
        self::assertSame(0, $this->driver()->usedMinutes('acme', '2026-10'));
    }

    public function test_related_states_can_be_created_and_booked_in_one_flush(): void
    {
        self::getContainer()->get(UnitOfWorkRunner::class)->run(function (): void {
            $this->driver()->registerCompany('acme', 'Acme', 60);
            $this->driver()->registerMember('ada', 'acme', 'ada@example.test');
            $this->driver()->openRoom('blue', 'Blue room');
            $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        });
        self::assertSame(['b1'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
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
