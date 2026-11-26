<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncRetryPolicy;
use Ultra\MessageHandling\Tests\Support\QueueTestCase;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class QueueSchedulingTest extends QueueTestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function schedules(): iterable
    {
        foreach (self::backends() as $backend => [$implementation]) {
            yield $backend . ' relative' => [$implementation, false];
            yield $backend . ' absolute' => [$implementation, true];
        }
    }

    #[DataProvider('schedules')]
    public function test_scheduled_messages_become_available_at_the_requested_time(
        string $backend,
        bool $absolute,
    ): void {
        $f = $this->fixture($backend);
        $options = $absolute
            ? new AsyncEnqueueOptions(availableAt: DatePoint::create(2026, 1, 1, 0, 0, 10))
            : new AsyncEnqueueOptions(delay: Duration::seconds(10));
        $f->queue->enqueue($this->envelope('scheduled'), $options);
        static::assertNull($f->queue->reserve());
        $f->clock->sleep(Duration::seconds(9));
        static::assertNull($f->queue->reserve());
        $f->clock->sleep(Duration::seconds(1));
        $delivery = $f->queue->reserve();
        static::assertNotNull($delivery);
        static::assertSame('scheduled', $delivery->envelope->message->value);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function releaseOutcomes(): iterable
    {
        foreach (self::backends() as $backend => [$implementation]) {
            yield $backend . ' explicit postponement' => [$implementation, false];
            yield $backend . ' override policy after failure' => [$implementation, true];
        }
    }

    #[DataProvider('releaseOutcomes')]
    public function test_release_uses_explicit_delay_even_when_a_retry_delay_is_configured(
        string $backend,
        bool $failed,
    ): void {
        $f = $this->fixture($backend, new AsyncRetryPolicy(retryDelay: Duration::seconds(30)));
        $f->queue->enqueue($this->envelope('retry'));
        $delivery = $f->queue->reserve();
        static::assertNotNull($delivery);
        $f->queue->release($delivery, Duration::seconds(20), $failed ? new \RuntimeException('failed') : null);
        static::assertNull($f->queue->reserve());
        $f->clock->sleep(Duration::seconds(20));
        $retry = $f->queue->reserve();
        static::assertNotNull($retry);
        static::assertSame($delivery->messageId->value, $retry->messageId->value);
        static::assertSame(2, $retry->attempts);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'relative and absolute' => [
            ['delay' => Duration::seconds(10), 'availableAt' => DatePoint::create(2026, 1, 1)],
            'cannot define both delay and availableAt',
        ];
        yield 'empty key' => [['key' => ''], 'key must be null or a non-empty string'];
        yield 'replacement without key' => [['replace' => true], 'replacement requires a key'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidOptions')]
    public function test_invalid_enqueue_options_are_rejected(array $options, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new AsyncEnqueueOptions(...$options);
    }
}
