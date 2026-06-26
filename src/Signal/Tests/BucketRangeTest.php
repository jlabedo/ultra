<?php

declare(strict_types=1);

namespace Ultra\Signal\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Signal\BucketRange;

#[CoversClass(BucketRange::class)]
final class BucketRangeTest extends TestCase
{
    public function test_single_bucket_range_covers_only_bucket_zero(): void
    {
        $range = BucketRange::single();

        self::assertTrue($range->contains(0));
        self::assertFalse($range->contains(1));
        self::assertFalse($range->contains(-1));
    }

    public function test_rejects_empty_or_inverted_ranges(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BucketRange::fromTo(10, 10);
    }
}
