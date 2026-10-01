<?php

declare(strict_types=1);

namespace App\Tests;

use App\Booking\Domain\Slot;
use App\Kernel;
use App\Tests\Driver\BookingDriver;
use App\Tests\Driver\EventSourcedDriver;
use App\Tests\Driver\StateStoreDriver;
use App\Tests\Driver\StateStoreDirectDriver;
use App\Booking\StateStoreDirect\BookingService as DirectBookingService;
use App\Booking\StateStoreDirect\Repository\BookingRepository as DirectBookingRepository;
use Psr\Container\ContainerInterface;
use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\UnitOfWorkRunner;

/**
 * Races are simulated deterministically: the first decision is loaded and buffered inside an open unit of work,
 * then a second kernel (its own connection) commits a competing decision before the first one flushes.
 *
 * The classic flavor has no equivalent test: its safety comes from pessimistic locks, and a lock only shows up
 * with two truly concurrent processes.
 *
 * @internal
 */
final class ConcurrentDecisionsTest extends CoworkingKernelTestCase
{
    private ?Kernel $otherKernel = null;

    protected function tearDown(): void
    {
        $this->otherKernel?->shutdown();
        $this->otherKernel = null;

        parent::tearDown();
    }

    public static function flavors(): iterable
    {
        yield 'event sourced' => ['eventSourced'];
        yield 'state store' => ['stateStore'];
        yield 'state store direct' => ['stateStoreDirect'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flavors')]
    public function test_a_concurrent_booking_of_the_same_slot_is_rejected(string $flavor): void
    {
        [$first, $second] = $this->drivers($flavor);
        $this->given($first);

        $this->assertLosesRace(fn () => $first->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00')), fn () => $second->book('b2', 'blue', 'grace', Slot::of('2026-09-10 10:30', '2026-09-10 11:30')));

        self::assertSame(['b2'], $first->bookingsOnDay('blue', '2026-09-10'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flavors')]
    public function test_a_concurrent_booking_draining_the_same_quota_is_rejected(string $flavor): void
    {
        [$first, $second] = $this->drivers($flavor);
        $this->given($first, monthlyMinutes: 60);

        $this->assertLosesRace(fn () => $first->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00')), fn () => $second->book('b2', 'red', 'grace', Slot::of('2026-09-11 10:00', '2026-09-11 11:00')));

        self::assertSame(60, $first->usedMinutes('acme', '2026-09'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flavors')]
    public function test_a_booking_decided_on_a_quota_lowered_concurrently_is_rejected(string $flavor): void
    {
        [$first, $second] = $this->drivers($flavor);
        $this->given($first, monthlyMinutes: 120);

        $this->assertLosesRace(fn () => $first->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00')), fn () => $second->changeCompanyPlan('acme', 30));

        self::assertSame(0, $first->usedMinutes('acme', '2026-09'));
    }

    public function test_direct_plan_changes_reject_concurrent_updates_without_a_manual_guard(): void
    {
        [$first, $second] = $this->drivers('stateStoreDirect');
        $this->given($first, monthlyMinutes: 120);

        $this->assertLosesRace(fn () => $first->changeCompanyPlan('acme', 30), fn () => $second->changeCompanyPlan('acme', 60));

        $first->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        self::assertSame(60, $first->usedMinutes('acme', '2026-09'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flavors')]
    public function test_unrelated_bookings_do_not_conflict(string $flavor): void
    {
        [$first, $second] = $this->drivers($flavor);
        $this->given($first);
        $first->registerCompany('globex', 'Globex', 600);
        $first->registerMember('hank', 'globex', 'hank@example.test');

        self::getContainer()->get(UnitOfWorkRunner::class)->run(function () use ($first, $second): void {
            $first->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
            $second->book('b2', 'red', 'hank', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));
        });

        self::assertSame(['b1'], $first->bookingsOnDay('blue', '2026-09-10'));
        self::assertSame(['b2'], $first->bookingsOnDay('red', '2026-09-10'));
    }

    private function assertLosesRace(callable $loser, callable $winner): void
    {
        try {
            self::getContainer()->get(UnitOfWorkRunner::class)->run(function () use ($loser, $winner): void {
                $loser();
                $winner();
            });
        } catch (\Ultra\EventStore\Exception\ConcurrencyException|\Ultra\UnitOfWork\Exception\ConcurrencyException) {
            return;
        }

        self::fail('The first decision should have lost the race.');
    }

    private function given(BookingDriver $driver, int $monthlyMinutes = 600): void
    {
        $driver->registerCompany('acme', 'Acme', $monthlyMinutes);
        $driver->registerMember('ada', 'acme', 'ada@example.test');
        $driver->registerMember('grace', 'acme', 'grace@example.test');
        $driver->openRoom('blue', 'Blue room');
        $driver->openRoom('red', 'Red room');
    }

    /**
     * @return array{BookingDriver, BookingDriver}
     */
    private function drivers(string $flavor): array
    {
        $this->otherKernel = new Kernel('test', true);
        $this->otherKernel->boot();
        $other = $this->otherKernel->getContainer()->get('test.service_container');

        return [$this->driver($flavor, self::getContainer()), $this->driver($flavor, $other)];
    }

    private function driver(string $flavor, ContainerInterface $container): BookingDriver
    {
        if ('stateStoreDirect' === $flavor) {
            return new StateStoreDirectDriver($container->get(DirectBookingService::class), $container->get(DirectBookingRepository::class), $container->get(StateStore::class));
        }

        return 'eventSourced' === $flavor
            ? new EventSourcedDriver($container->get(MediatorInterface::class), $container->get(EventSourcedStateStore::class))
            : new StateStoreDriver($container->get(MediatorInterface::class), $container->get(StateStore::class));
    }
}
