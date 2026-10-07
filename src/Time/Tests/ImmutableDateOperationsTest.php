<?php

declare(strict_types=1);

namespace Ultra\Time\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

/** @internal */
#[CoversClass(DatePoint::class)]
final class ImmutableDateOperationsTest extends TestCase
{
    /** @param array<string, int|\DateTimeZone> $components */
    #[DataProvider('components')]
    public function test_creation_preserves_components_and_timezone(array $components, string $expected): void
    {
        $result = DatePoint::create(...$components);

        static::assertInstanceOf(DatePoint::class, $result);
        static::assertSame($expected, $result->format('Y-m-d H:i:s.u e'));
    }

    /** @return iterable<string, array{array<string, int|\DateTimeZone>, string}> */
    public static function components(): iterable
    {
        yield 'all defaults' => [[], '0000-01-01 00:00:00.000000 ' . date_default_timezone_get()];
        yield 'date only' => [
            ['year' => 2026, 'month' => 4, 'day' => 22],
            '2026-04-22 00:00:00.000000 ' . date_default_timezone_get(),
        ];
        yield 'precision and timezone' => [
            [
                'year' => 2026,
                'month' => 4,
                'day' => 22,
                'hour' => 10,
                'minute' => 11,
                'second' => 12,
                'microsecond' => 123_456,
                'timezone' => new \DateTimeZone('America/New_York'),
            ],
            '2026-04-22 10:11:12.123456 America/New_York',
        ];
    }

    /** @param \Closure(DatePoint): DatePoint $operation */
    #[DataProvider('operations')]
    public function test_operations_preserve_the_date_point_type_and_leave_the_original_unchanged(
        \Closure $operation,
        string $expected,
    ): void {
        $original = new class('2026-04-22 00:00:00.100000', new \DateTimeZone('UTC')) extends DatePoint {
            public string $label = 'initial';
        };
        $original->label = 'assigned after construction';

        $result = $operation($original);

        static::assertInstanceOf($original::class, $result);
        static::assertSame('assigned after construction', $result->label);
        static::assertNotSame($original, $result);
        static::assertSame($expected, $result->format('Y-m-d H:i:s.u e'));
        static::assertSame('2026-04-22 00:00:00.100000', (string) $original);
    }

    /** @return iterable<string, array{\Closure(DatePoint): DatePoint, string}> */
    public static function operations(): iterable
    {
        yield 'add duration' => [
            static fn(DatePoint $date) => $date->add(Duration::minutes(1.5)),
            '2026-04-22 00:01:30.100000 UTC',
        ];
        yield 'subtract duration across midnight' => [
            static fn(DatePoint $date) => $date->sub(Duration::milliseconds(250)),
            '2026-04-21 23:59:59.850000 UTC',
        ];
        yield 'add native interval' => [
            static fn(DatePoint $date) => $date->add(new \DateInterval('PT2H')),
            '2026-04-22 02:00:00.100000 UTC',
        ];
        yield 'subtract native interval' => [
            static fn(DatePoint $date) => $date->sub(new \DateInterval('PT2H')),
            '2026-04-21 22:00:00.100000 UTC',
        ];
        yield 'relative modification' => [
            static fn(DatePoint $date) => $date->modify('+1 day'),
            '2026-04-23 00:00:00.100000 UTC',
        ];
        yield 'timestamp' => [
            static fn(DatePoint $date) => $date->setTimestamp(1_749_988_800),
            '2025-06-15 12:00:00.000000 UTC',
        ];
        yield 'named timestamp' => [
            static fn(DatePoint $date) => $date->setTimestamp(timestamp: 1_749_988_800),
            '2025-06-15 12:00:00.000000 UTC',
        ];
        yield 'calendar date' => [
            static fn(DatePoint $date) => $date->setDate(2026, 4, 23),
            '2026-04-23 00:00:00.100000 UTC',
        ];
        yield 'ISO date' => [
            static fn(DatePoint $date) => $date->setISODate(2026, 17, 3),
            '2026-04-22 00:00:00.100000 UTC',
        ];
        yield 'named ISO weekday' => [
            static fn(DatePoint $date) => $date->setISODate(year: 2026, week: 17, dayOfWeek: 3),
            '2026-04-22 00:00:00.100000 UTC',
        ];
        yield 'ISO date default day' => [
            static fn(DatePoint $date) => $date->setISODate(2026, 17),
            '2026-04-20 00:00:00.100000 UTC',
        ];
        yield 'precise time' => [
            static fn(DatePoint $date) => $date->setTime(15, 30, 45, 500_000),
            '2026-04-22 15:30:45.500000 UTC',
        ];
        yield 'time default fractions' => [
            static fn(DatePoint $date) => $date->setTime(15, 30),
            '2026-04-22 15:30:00.000000 UTC',
        ];
        yield 'timezone' => [
            static fn(DatePoint $date) => $date->setTimezone(new \DateTimeZone('Europe/Paris')),
            '2026-04-22 02:00:00.100000 Europe/Paris',
        ];
    }
}
