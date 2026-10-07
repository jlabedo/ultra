<?php

declare(strict_types=1);

namespace Ultra\Time\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\Time\Duration;

/** @internal */
#[CoversClass(Duration::class)]
final class ElapsedTimeArithmeticTest extends TestCase
{
    public function test_signed_arithmetic_preserves_the_operands(): void
    {
        $left = Duration::seconds(1.5);
        $right = Duration::milliseconds(250);

        static::assertSame(1_750_000, $left->add($right)->inMicroseconds());
        static::assertSame(1_250_000, $left->sub($right)->inMicroseconds());
        static::assertSame(1_250_000, $left->subtract($right)->inMicroseconds());
        static::assertSame(-1_500_000, $left->negate()->inMicroseconds());
        static::assertSame(1_500_000, $left->negate()->absolute()->inMicroseconds());
        static::assertSame(1_500_000, $left->inMicroseconds());
        static::assertSame(250_000, $right->inMicroseconds());
        static::assertSame(0, Duration::zero()->inMicroseconds());
    }

    #[DataProvider('units')]
    public function test_units_round_to_the_nearest_microsecond(string $unit, float|int $value, int $expected): void
    {
        static::assertSame($expected, Duration::$unit($value)->inMicroseconds());
    }

    /** @return iterable<string, array{string, float|int, int}> */
    public static function units(): iterable
    {
        yield 'microseconds' => ['microseconds', 1, 1];
        yield 'positive fractional millisecond' => ['milliseconds', 0.0006, 1];
        yield 'negative fractional millisecond' => ['milliseconds', -0.0006, -1];
        yield 'fractional second' => ['seconds', 1.000_000_6, 1_000_001];
        yield 'minutes' => ['minutes', 1.5, 90_000_000];
        yield 'hours' => ['hours', 2, 7_200_000_000];
        yield 'days' => ['days', 1, 86_400_000_000];
    }

    /**
     * @param array<string, int> $components
     * @param array{int, int, int, int, float, int} $expected
     */
    #[DataProvider('components')]
    public function test_components_convert_to_a_signed_native_interval(array $components, array $expected): void
    {
        $interval = new Duration(...$components)->toDateInterval();

        static::assertSame($expected, [
            $interval->d,
            $interval->h,
            $interval->i,
            $interval->s,
            $interval->f,
            $interval->invert,
        ]);
    }

    /** @return iterable<string, array{array<string, int>, array{int, int, int, int, float, int}}> */
    public static function components(): iterable
    {
        yield 'all components' => [
            ['microseconds' => 500_000, 'seconds' => 4, 'minutes' => 3, 'hours' => 2, 'days' => 1],
            [1, 2, 3, 4, 0.5, 0],
        ];
        yield 'negative fraction' => [['microseconds' => -250_000], [0, 0, 0, 0, 0.25, 1]];
        yield 'zero defaults' => [[], [0, 0, 0, 0, 0.0, 0]];
    }
}
