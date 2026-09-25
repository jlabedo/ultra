<?php

declare(strict_types=1);

namespace App\Tests;

use App\Booking\Domain\Slot;
use App\Booking\EventSourced\Write\BookRoom;
use App\Tests\Driver\BookingDriver;
use App\Tests\Driver\EventSourcedDriver;
use PHPUnit\Framework\Attributes\Group;
use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\StatefulMessageHandling\Violation;

/**
 * @internal
 */
final class EventSourcedBookingTest extends BookingScenarioTestCase
{
    protected function driver(): BookingDriver
    {
        return new EventSourcedDriver(
            self::getContainer()->get(MediatorInterface::class),
            self::getContainer()->get(EventSourcedStateStore::class),
        );
    }

    /**
     * Known gap: check mode passes null for every non-state parameter of check(), so a service like the clock
     * cannot be used there.
     */
    #[Group('finding')]
    public function test_check_mode_reports_violations_without_writing(): void
    {
        $this->givenAcmeWithAdaAndGrace();
        $this->driver()->book('b1', 'blue', 'ada', Slot::of('2026-09-10 10:00', '2026-09-10 11:00'));

        $violations = self::getContainer()->get(MediatorInterface::class)->send(
            new BookRoom('b2', 'blue', 'grace', 'acme', Slot::of('2026-09-10 10:30', '2026-09-10 11:30')),
            ['decision_mode' => 'check'],
        );

        self::assertSame(['slot.unavailable'], array_map(static fn (Violation $violation): string => $violation->code, $violations));
        self::assertSame(['b1'], $this->driver()->bookingsOnDay('blue', '2026-09-10'));
    }
}
