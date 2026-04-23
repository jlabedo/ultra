<?php

declare(strict_types=1);

namespace Ultra\Time\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Time\DatePoint;
use Ultra\Time\SystemClock;

/**
 * @internal
 */
#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function test_system_clock_returns_a_date_point(): void
    {
        $clock = new SystemClock();

        self::assertInstanceOf(DatePoint::class, $clock->now());
    }
}
