<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests\Async;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

#[CoversClass(AsyncEnqueueOptions::class)]
final class AsyncEnqueueOptionsTest extends TestCase
{
    public function test_it_rejects_delay_and_available_at_together(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot define both delay and availableAt');

        new AsyncEnqueueOptions(
            delay: Duration::seconds(10),
            availableAt: DatePoint::create(2026, 1, 1),
        );
    }

    public function test_it_rejects_empty_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('key must be null or a non-empty string');

        new AsyncEnqueueOptions(key: '');
    }

    public function test_it_requires_key_for_replacement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('replacement requires a key');

        new AsyncEnqueueOptions(replace: true);
    }
}
