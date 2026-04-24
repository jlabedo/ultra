<?php

declare(strict_types=1);

namespace Ultra\Time\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

/**
 * @internal
 */
#[CoversClass(DatePoint::class)]
final class DatePointTest extends TestCase
{
    public function test_date_point_can_add_a_duration(): void
    {
        $datePoint = new DatePoint('2026-04-22 10:11:12.123456', new \DateTimeZone('UTC'));

        $result = $datePoint->add(Duration::minutes(1.5));

        self::assertInstanceOf(DatePoint::class, $result);
        self::assertSame('2026-04-22 10:12:42.123456', $result->format('Y-m-d H:i:s.u'));
    }

    public function test_date_point_can_subtract_a_duration(): void
    {
        $datePoint = new DatePoint('2026-04-22 00:00:00.100000', new \DateTimeZone('UTC'));

        $result = $datePoint->sub(Duration::milliseconds(250));

        self::assertInstanceOf(DatePoint::class, $result);
        self::assertSame('2026-04-21 23:59:59.850000', $result->format('Y-m-d H:i:s.u'));
    }

    public function test_date_point_still_accepts_native_date_intervals(): void
    {
        $datePoint = new DatePoint('2026-04-22 10:11:12', new \DateTimeZone('UTC'));

        $result = $datePoint->add(new \DateInterval('PT2H'));

        self::assertSame('2026-04-22 12:11:12.000000', $result->format('Y-m-d H:i:s.u'));
    }

    public function test_create_builds_date_from_components(): void
    {
        $datePoint = DatePoint::create(2026, 4, 22, 10, 11, 12, 123456);

        self::assertInstanceOf(DatePoint::class, $datePoint);
        self::assertSame('2026-04-22 10:11:12.123456', $datePoint->format('Y-m-d H:i:s.u'));
    }

    public function test_create_uses_defaults_for_omitted_components(): void
    {
        $datePoint = DatePoint::create(2026, 4, 22);

        self::assertSame('2026-04-22 00:00:00.000000', $datePoint->format('Y-m-d H:i:s.u'));
    }

    public function test_create_respects_timezone(): void
    {
        $tz = new \DateTimeZone('America/New_York');
        $datePoint = DatePoint::create(2026, 1, 1, timezone: $tz);

        self::assertSame('America/New_York', $datePoint->getTimezone()->getName());
    }

    public function test_modify_returns_date_point_instance(): void
    {
        $datePoint = new DatePoint('2026-04-22 10:00:00', new \DateTimeZone('UTC'));

        $result = $datePoint->modify('+1 day');

        self::assertInstanceOf(DatePoint::class, $result);
        self::assertSame('2026-04-23 10:00:00.000000', $result->format('Y-m-d H:i:s.u'));
    }

    public function test_set_timestamp_returns_date_point_instance(): void
    {
        $datePoint = new DatePoint('now', new \DateTimeZone('UTC'));
        $timestamp = mktime(12, 0, 0, 6, 15, 2025);

        $result = $datePoint->setTimestamp($timestamp);

        self::assertInstanceOf(DatePoint::class, $result);
        self::assertSame('2025-06-15', $result->format('Y-m-d'));
    }

    public function test_set_date_returns_date_point_instance(): void
    {
        $datePoint = new DatePoint('2026-01-01 10:00:00', new \DateTimeZone('UTC'));

        $result = $datePoint->setDate(2026, 4, 22);

        self::assertInstanceOf(DatePoint::class, $result);
        self::assertSame('2026-04-22', $result->format('Y-m-d'));
        self::assertSame('10:00:00', $result->format('H:i:s'));
    }

    public function test_set_iso_date_returns_date_point_instance(): void
    {
        $datePoint = new DatePoint('2026-01-01', new \DateTimeZone('UTC'));

        $result = $datePoint->setISODate(2026, 17, 3);

        self::assertInstanceOf(DatePoint::class, $result);
        self::assertSame('2026-W17-3', $result->format('o-\WW-N'));
    }

    public function test_set_time_returns_date_point_instance(): void
    {
        $datePoint = new DatePoint('2026-04-22 00:00:00', new \DateTimeZone('UTC'));

        $result = $datePoint->setTime(15, 30, 45, 500000);

        self::assertInstanceOf(DatePoint::class, $result);
        self::assertSame('15:30:45.500000', $result->format('H:i:s.u'));
    }

    public function test_set_timezone_returns_date_point_instance(): void
    {
        $datePoint = new DatePoint('2026-04-22 10:00:00', new \DateTimeZone('UTC'));
        $tz = new \DateTimeZone('Europe/Paris');

        $result = $datePoint->setTimezone($tz);

        self::assertInstanceOf(DatePoint::class, $result);
        self::assertSame('Europe/Paris', $result->getTimezone()->getName());
        self::assertSame('2026-04-22 12:00:00.000000', $result->format('Y-m-d H:i:s.u'));
    }
}
