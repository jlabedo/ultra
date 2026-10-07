<?php

declare(strict_types=1);

namespace App\Tests;

use App\Booking\Domain\Slot;
use App\Booking\StateStore\Repository\BookingRepository;
use App\Booking\StateStore\Write\BookWeekly;
use App\Tests\Driver\BookingDriver;
use App\Tests\Driver\StateStoreDriver;
use Doctrine\DBAL\Connection;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\StatefulMessageHandling\DecisionMode;
use Ultra\StatefulMessageHandling\Violation;
use Ultra\StateStore\Exception\PendingQueryChangesException;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/**
 * @internal
 */
final class StateStoreBookingTest extends BookingScenarioTestCase
{
    public function test_check_mode_validates_the_entire_weekly_series_without_saving_occurrences(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 120);
        $mediator = self::getContainer()->get(MediatorInterface::class);
        $slot = Slot::of('2026-09-08 10:00', '2026-09-08 11:00');
        self::assertSame([], $mediator->send(new BookWeekly('valid', 'blue', 'ada', $slot, 2), [
            DecisionMode::CONTEXT_KEY => DecisionMode::CHECK,
        ]));
        self::assertNull($this->driver()->bookingStatus('valid#0'));
        self::assertNull($this->driver()->bookingStatus('valid#1'));
        self::assertSame(0, $this->driver()->usedMinutes('acme', '2026-09'));

        $violations = $mediator->send(new BookWeekly('invalid', 'blue', 'ada', $slot, 3), [
            DecisionMode::CONTEXT_KEY => DecisionMode::CHECK,
        ]);
        self::assertSame(
            ['quota.exceeded'],
            array_map(static fn(Violation $violation): string => $violation->code, $violations),
        );
        self::assertNull($this->driver()->bookingStatus('invalid#0'));
        $this->driver()->bookWeekly('valid', 'blue', 'ada', $slot, 2);
        self::assertSame(120, $this->driver()->usedMinutes('acme', '2026-09'));
    }

    public function test_a_second_booking_after_affected_writes_is_rejected_and_both_decisions_are_discarded(): void
    {
        $this->givenAcmeWithAdaAndGrace(monthlyMinutes: 120);
        $connection = self::getContainer()->get(Connection::class);
        try {
            self::getContainer()
                ->get(UnitOfWorkRunner::class)
                ->run(function () use ($connection): void {
                    $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
                    self::assertFalse($connection->isTransactionActive());
                    $this->driver()->book('b2', 'red', 'grace', Slot::of('2026-09-11 10:00', '2026-09-11 11:00'));
                });
            self::fail('A decision query must reject affected pending writes.');
        } catch (PendingQueryChangesException $exception) {
            self::assertSame('state_store_company_usage', $exception->guardName);
        }
        self::assertNull($this->driver()->bookingStatus('b1'));
        self::assertNull($this->driver()->bookingStatus('b2'));
        self::assertSame(0, $this->driver()->usedMinutes('acme', '2026-09'));
        $this->driver()->book('after-failure', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        self::assertSame(60, $this->driver()->usedMinutes('acme', '2026-09'));
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

    protected function driver(): BookingDriver
    {
        return new StateStoreDriver(
            self::getContainer()->get(MediatorInterface::class),
            self::getContainer()->get(StateStore::class),
            self::getContainer()->get(BookingRepository::class),
        );
    }
}
