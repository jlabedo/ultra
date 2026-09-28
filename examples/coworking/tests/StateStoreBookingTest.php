<?php

declare(strict_types=1);

namespace App\Tests;

use Ultra\MessageHandling\MediatorInterface;
use Ultra\StateStore\StateStore;
use App\Tests\Driver\BookingDriver;
use App\Tests\Driver\StateStoreDriver;

/**
 * @internal
 */
final class StateStoreBookingTest extends BookingScenarioTestCase
{
    protected function driver(): BookingDriver
    {
        return new StateStoreDriver(
            self::getContainer()->get(MediatorInterface::class),
            self::getContainer()->get(StateStore::class),
        );
    }
}
