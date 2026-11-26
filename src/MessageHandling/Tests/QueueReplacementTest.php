<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncRetryPolicy;
use Ultra\MessageHandling\Tests\Support\QueueTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class QueueReplacementTest extends QueueTestCase
{
    #[DataProvider('backends')]
    public function test_replacement_keeps_only_the_latest_message_for_the_same_key(string $backend): void
    {
        $f = $this->fixture($backend);
        $f->queue->enqueue($this->envelope('old'), new AsyncEnqueueOptions(key: 'refresh', replace: true));
        $id = $f->queue->enqueue($this->envelope('new'), new AsyncEnqueueOptions(key: 'refresh', replace: true));
        static::assertSame(1, $f->count());
        $delivery = $f->queue->reserve();
        static::assertNotNull($delivery);
        static::assertSame($id->value, $delivery->messageId->value);
        static::assertSame('new', $delivery->envelope->message->value);
        $f->queue->acknowledge($delivery);
        static::assertNull($f->queue->reserve());
    }

    #[DataProvider('backends')]
    public function test_replacement_using_a_quarantined_message_id_preserves_the_pending_message(string $backend): void
    {
        $f = $this->fixture($backend, new AsyncRetryPolicy(maxAttempts: 1));
        $failedId = $f->queue->enqueue($this->envelope('failed'));
        $failed = $f->queue->reserve();
        static::assertNotNull($failed);
        $f->queue->release($failed, reason: new \RuntimeException('failed'));
        $pendingId = $f->queue->enqueue(
            $this->envelope('pending'),
            new AsyncEnqueueOptions(key: 'refresh', replace: true),
        );

        try {
            $f->queue->enqueue(
                $this->envelope('invalid replacement'),
                new AsyncEnqueueOptions(messageId: $failedId, key: 'refresh', replace: true),
            );
            static::fail('Expected an enqueue to reject the quarantined message ID.');
        } catch (\InvalidArgumentException|UniqueConstraintViolationException) {
            static::assertNotNull($f->queue->inspectFailed($failedId));
            static::assertSame($pendingId->value, $f->queue->reserve()?->messageId->value);
        }
    }
}
