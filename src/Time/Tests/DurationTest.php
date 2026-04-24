<?php

declare(strict_types=1);

namespace Ultra\Time\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Time\Duration;

/**
 * @internal
 */
#[CoversClass(Duration::class)]
final class DurationTest extends TestCase
{
    public function test_durations_can_be_added(): void
    {
        $duration = Duration::seconds(1.5)->add(Duration::milliseconds(250));

        self::assertSame(1_750_000, $duration->microseconds);
    }

    public function test_durations_can_be_subtracted(): void
    {
        $duration = Duration::seconds(3)->subtract(Duration::milliseconds(750));

        self::assertSame(2_250_000, $duration->microseconds);
    }

    public function test_duration_can_be_converted_to_a_date_interval(): void
    {
        $interval = Duration::days(1)
            ->add(Duration::hours(2))
            ->add(Duration::minutes(3))
            ->add(Duration::seconds(4.5))
            ->toDateInterval()
        ;

        self::assertSame(1, $interval->d);
        self::assertSame(2, $interval->h);
        self::assertSame(3, $interval->i);
        self::assertSame(4, $interval->s);
        self::assertSame(0.5, $interval->f);
        self::assertSame(0, $interval->invert);
    }

    public function test_negative_duration_produces_an_inverted_date_interval(): void
    {
        $interval = Duration::milliseconds(-250)->toDateInterval();

        self::assertSame(0, $interval->s);
        self::assertSame(0.25, $interval->f);
        self::assertSame(1, $interval->invert);
    }
}
