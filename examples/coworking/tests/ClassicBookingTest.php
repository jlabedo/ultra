<?php

declare(strict_types=1);

namespace App\Tests;

use App\Booking\Classic\BookingService;
use App\Booking\Classic\Repository\BookingRepository;
use App\Tests\Driver\BookingDriver;
use App\Tests\Driver\ClassicDriver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @internal
 */
final class ClassicBookingTest extends BookingScenarioTestCase
{
    protected function driver(): BookingDriver
    {
        return new ClassicDriver(
            self::getContainer()->get(BookingService::class),
            self::getContainer()->get(BookingRepository::class),
            self::getContainer()->get(EntityManagerInterface::class),
        );
    }
}
