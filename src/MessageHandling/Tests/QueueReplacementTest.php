<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncMessageId;
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

    /** @return iterable<string, array{bool}> */
    public static function replacementIds(): iterable
    {
        yield 'generated ID' => [false];
        yield 'reused ID' => [true];
    }

    #[DataProvider('replacementIds')]
    public function test_postgres_replacement_removes_all_pending_messages_for_the_same_key(bool $reuseId): void
    {
        $f = $this->fixture('postgres');
        $key = new AsyncEnqueueOptions(key: 'refresh');
        $previousId = $f->queue->enqueue($this->envelope('leased'), $key);
        static::assertSame($previousId->value, $f->queue->reserve()?->messageId->value);
        $f->queue->enqueue($this->envelope('unleased'), $key);
        static::assertSame(2, $f->count());

        $id = $f->queue->enqueue(
            $this->envelope('replacement'),
            new AsyncEnqueueOptions(messageId: $reuseId ? $previousId : null, key: 'refresh', replace: true),
        );

        static::assertSame(1, $f->count());
        $replacement = $f->queue->reserve();
        static::assertNotNull($replacement);
        static::assertSame(($reuseId ? $previousId : $id)->value, $replacement->messageId->value);
        static::assertSame('replacement', $replacement->envelope->message->value);
        static::assertSame(1, $replacement->attempts);
    }

    public function test_in_memory_acknowledgement_clears_all_keys_for_a_message_id(): void
    {
        $f = $this->fixture();
        $id = new AsyncMessageId('1');
        $keys = ['10', '20'];
        foreach ($keys as $key) {
            $f->queue->enqueue($this->envelope('original'), new AsyncEnqueueOptions(messageId: $id, key: $key));
        }
        $delivery = $f->queue->reserve();
        static::assertNotNull($delivery);
        $f->queue->acknowledge($delivery);

        $f->queue->enqueue($this->envelope('reused ID'), new AsyncEnqueueOptions(messageId: $id));
        foreach ($keys as $key) {
            $f->queue->enqueue($this->envelope('replacement'), new AsyncEnqueueOptions(key: $key, replace: true));
        }

        static::assertSame(3, $f->count());
        static::assertSame('reused ID', $f->queue->reserve()?->envelope->message->value);
    }

    public function test_in_memory_acknowledgement_preserves_a_key_reassigned_to_another_message(): void
    {
        $f = $this->fixture();
        $options = new AsyncEnqueueOptions(key: 'refresh');
        $firstId = $f->queue->enqueue($this->envelope('first'), $options);
        $f->queue->enqueue($this->envelope('second'), $options);

        $delivery = $f->queue->reserve();
        static::assertNotNull($delivery);
        static::assertSame($firstId->value, $delivery->messageId->value);
        $f->queue->acknowledge($delivery);

        $replacement = new AsyncEnqueueOptions(key: 'refresh', replace: true);
        $replacementId = $f->queue->enqueue($this->envelope('replacement'), $replacement);
        static::assertSame(1, $f->count());
        static::assertSame($replacementId->value, $f->queue->reserve()?->messageId->value);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function replacementConflictTransactions(): iterable
    {
        yield 'memory' => ['memory', false];
        yield 'Postgres' => ['postgres', false];
        yield 'Postgres in a transaction' => ['postgres', true];
    }

    #[DataProvider('replacementConflictTransactions')]
    public function test_replacement_using_a_quarantined_message_id_preserves_the_pending_message(
        string $backend,
        bool $inTransaction,
    ): void {
        $f = $this->fixture($backend, new AsyncRetryPolicy(maxAttempts: 1));
        $failedId = $f->queue->enqueue($this->envelope('failed'));
        $failed = $f->queue->reserve();
        static::assertNotNull($failed);
        $f->queue->release($failed, reason: new \RuntimeException('failed'));
        if ($inTransaction) {
            $f->connection->beginTransaction();
        }
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
        if ($inTransaction) {
            $f->connection->commit();
        }
    }
}
