<?php

declare(strict_types=1);

namespace Ultra\Time\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;
use Ultra\Time\SystemClock;

/** @internal */
#[CoversClass(DatePoint::class)]
#[CoversClass(StatickClock::class)]
#[CoversClass(SystemClock::class)]
final class ClockProgressionTest extends TestCase
{
    public function test_controlled_time_can_advance_and_be_reset_without_changing_previous_readings(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-04-22 10:11:12.123456', new \DateTimeZone('UTC')));
        $before = $clock->now();
        self::assertSame('2026-04-22 10:11:12.123456', (string) $before);

        $clock->sleep(Duration::seconds(30));

        self::assertSame('2026-04-22 10:11:42.123456', (string) $clock->now());
        self::assertSame('2026-04-22 10:11:12.123456', (string) $before);

        $reset = new DatePoint('2026-04-22 12:00:00', new \DateTimeZone('UTC'));
        $clock->setNow($reset);

        self::assertSame($reset, $clock->now());
        self::assertSame('2026-04-22 12:00:00.000000', (string) $clock->now());
    }

    public function test_system_time_is_a_date_point_between_the_surrounding_readings(): void
    {
        $before = new \DateTimeImmutable('now');
        $now = (new SystemClock())->now();
        $after = new \DateTimeImmutable('now');

        self::assertInstanceOf(DatePoint::class, $now);
        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }

    public function test_system_sleep_waits_for_the_requested_duration(): void
    {
        $before = hrtime(true);
        (new SystemClock())->sleep(Duration::milliseconds(20));

        self::assertGreaterThanOrEqual(20_000_000, hrtime(true) - $before);
    }
}
