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

        $result = $datePoint->subtract(Duration::milliseconds(250));

        self::assertInstanceOf(DatePoint::class, $result);
        self::assertSame('2026-04-21 23:59:59.850000', $result->format('Y-m-d H:i:s.u'));
    }

    public function test_date_point_still_accepts_native_date_intervals(): void
    {
        $datePoint = new DatePoint('2026-04-22 10:11:12', new \DateTimeZone('UTC'));

        $result = $datePoint->add(new \DateInterval('PT2H'));

        self::assertSame('2026-04-22 12:11:12.000000', $result->format('Y-m-d H:i:s.u'));
    }
}
