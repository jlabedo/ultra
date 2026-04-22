<?php

declare(strict_types=1);

namespace Ultra\Time\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/**
 * @internal
 */
#[CoversClass(StatickClock::class)]
final class StatickClockTest extends TestCase
{
    public function test_statick_clock_returns_the_configured_time(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-04-22 10:11:12.123456', new \DateTimeZone('UTC')));

        self::assertSame('2026-04-22 10:11:12.123456', $clock->now()->format('Y-m-d H:i:s.u'));
    }

    public function test_statick_clock_can_advance_by_a_duration(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-04-22 10:11:12.123456', new \DateTimeZone('UTC')));

        $clock->advance(Duration::seconds(30));

        self::assertSame('2026-04-22 10:11:42.123456', $clock->now()->format('Y-m-d H:i:s.u'));
    }

    public function test_statick_clock_can_be_reset(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-04-22 10:11:12.123456', new \DateTimeZone('UTC')));

        $clock->setNow(new \DateTimeImmutable('2026-04-22 12:00:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-04-22 12:00:00.000000', $clock->now()->format('Y-m-d H:i:s.u'));
    }
}
