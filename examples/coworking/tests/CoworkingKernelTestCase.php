<?php

declare(strict_types=1);

namespace App\Tests;

use App\Infrastructure\CoworkingSchema;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ultra\Time\StatickClock;
use Ultra\Time\ClockInterface;

abstract class CoworkingKernelTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        self::getContainer()->get(CoworkingSchema::class)->reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        restore_exception_handler();
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function clock(): StatickClock
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        \assert($clock instanceof StatickClock);

        return $clock;
    }
}
