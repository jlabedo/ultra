<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Container;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncHeaderNames;
use Ultra\MessageHandling\Async\AsyncMessageId;
use Ultra\MessageHandling\Async\AsyncRetryPolicy;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Handler\MessageHandlerInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\Tests\Support\QueueTestCase;
use Ultra\MessageHandling_PostgresQueue\AsyncEnvelopeCodecInterface;
use Ultra\MessageHandling_PostgresQueue\EncodedAsyncEnvelope;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Time\Duration;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class DeadLetterQueueTest extends QueueTestCase
{
    #[DataProvider('backends')]
    public function test_failed_handler_retries_then_quarantines_and_can_be_replayed(string $backend): void
    {
        $f = $this->fixture($backend, new AsyncRetryPolicy(maxAttempts: 2, retryDelay: Duration::seconds(10)));
        $id = $f->queue->enqueue($this->envelope('poison'));
        $failure = new \RuntimeException('handler unavailable');
        $fail = true;
        $handled = [];
        $handler = $this->createStub(MessageHandlerInterface::class);
        $handler
            ->method('__invoke')
            ->willReturnCallback(static function (object $message, MessageContext $context) use (
                &$fail,
                &$handled,
                $failure,
            ): void {
                $handled[] = [$message->value, $context->get('traceId')];
                if ('poison' === $message->value && $fail) {
                    throw $failure;
                }
            });
        $container = new Container();
        $container->set('test.route', $handler);
        $worker = new QueueWorker($f->queue, $container);

        $this->assertWorkerFailure($worker, $failure);
        static::assertSame(0, $f->queue->countFailed());
        static::assertNull($f->queue->inspectFailed($id));
        static::assertFalse($f->queue->replayFailed($id));
        $f->clock->sleep(Duration::seconds(9));
        static::assertFalse($worker->processOne());
        $f->clock->sleep(Duration::seconds(1));

        $this->assertWorkerFailure($worker, $failure);
        static::assertSame(1, $f->queue->countFailed());
        $failed = $f->queue->inspectFailed($id);
        static::assertNotNull($failed);
        static::assertSame($id->value, $failed->messageId->value);
        static::assertSame(2, $failed->attempts);
        static::assertEquals($f->clock->now(), $failed->failedAt);
        static::assertSame(\RuntimeException::class, $failed->failureClass);
        static::assertSame('handler unavailable', $failed->failureMessage);
        static::assertSame('test.route', $failed->envelope->headers->getString(AsyncHeaderNames::ROUTE));
        if ($failed->envelope instanceof AsyncEnvelope) {
            static::assertSame('poison', $failed->envelope->message->value);
            static::assertSame('trace-1', $failed->envelope->messageContext->get('traceId'));
        } else {
            static::assertSame('{"value":"poison"}', $failed->envelope->body);
            static::assertSame(['traceId' => 'trace-1'], $failed->envelope->attributes);
        }

        $f->queue->enqueue($this->envelope('healthy'));
        static::assertSame(1, $worker->drain());

        $fail = false;
        static::assertTrue($f->queue->replayFailed($id));
        static::assertFalse($f->queue->replayFailed($id));
        static::assertSame(0, $f->queue->countFailed());
        static::assertNull($f->queue->inspectFailed($id));
        static::assertSame(1, $worker->drain());
        static::assertSame(
            [
                ['poison',  'trace-1'],
                ['poison',  'trace-1'],
                ['healthy', 'trace-1'],
                ['poison',  'trace-1'],
            ],
            $handled,
        );
    }

    #[DataProvider('backends')]
    public function test_expired_final_lease_quarantines_without_a_new_attempt_and_replay_resets_the_budget(string $backend): void
    {
        $f = $this->fixture($backend, new AsyncRetryPolicy(maxAttempts: 1));
        $id = $f->queue->enqueue($this->envelope('expired'));
        $expired = $f->queue->reserve(Duration::seconds(10));
        static::assertNotNull($expired);
        static::assertNull($f->queue->reserve());
        static::assertSame(0, $f->queue->countFailed());

        $f->clock->sleep(Duration::seconds(11));
        $healthyId = $f->queue->enqueue($this->envelope('healthy'));
        $healthy = $f->queue->reserve();
        static::assertNotNull($healthy);
        static::assertSame($healthyId->value, $healthy->messageId->value);
        $f->queue->acknowledge($healthy);
        static::assertNull($f->queue->reserve());

        $failed = $f->queue->inspectFailed($id);
        static::assertNotNull($failed);
        static::assertSame(1, $failed->attempts);
        static::assertNotSame('', $failed->failureMessage);
        static::assertTrue($f->queue->replayFailed($id));
        $replayed = $f->queue->reserve();
        static::assertNotNull($replayed);
        static::assertSame($id->value, $replayed->messageId->value);
        static::assertSame(1, $replayed->attempts);
        static::assertNotSame($expired->leaseId, $replayed->leaseId);

        $f->queue->acknowledge($expired);
        $f->queue->release($expired, reason: new \RuntimeException('stale'));
        static::assertNull($f->queue->reserve());
        static::assertSame(0, $f->queue->countFailed());
        $f->queue->release($replayed, reason: new \RuntimeException('current'));
        static::assertSame('current', $f->queue->inspectFailed($id)?->failureMessage);
    }

    #[DataProvider('backends')]
    public function test_failed_listing_is_paginated_in_failure_time_and_id_order(string $backend): void
    {
        $f = $this->fixture($backend, new AsyncRetryPolicy(maxAttempts: 1));
        foreach (['b', 'a', '0-first-id'] as $value) {
            if ('0-first-id' === $value) {
                $f->clock->sleep(Duration::seconds(1));
            }
            $f->queue->enqueue($this->envelope($value), new AsyncEnqueueOptions(messageId: new AsyncMessageId($value)));
            $delivery = $f->queue->reserve();
            static::assertNotNull($delivery);
            $f->queue->release($delivery, reason: new \RuntimeException('failed'));
        }

        static::assertSame(3, $f->queue->countFailed());
        $firstPage = $f->queue->listFailed(limit: 2);
        static::assertCount(2, $firstPage);
        static::assertSame('a', $firstPage[0]->messageId->value);
        static::assertSame('b', $firstPage[1]->messageId->value);
        $secondPage = $f->queue->listFailed(limit: 2, offset: 2);
        static::assertCount(1, $secondPage);
        static::assertSame('0-first-id', $secondPage[0]->messageId->value);
        static::assertSame([], $f->queue->listFailed(offset: 3));
    }

    #[DataProvider('backends')]
    public function test_schedule_key_replacement_preserves_quarantine_and_replayed_messages(string $backend): void
    {
        $f = $this->fixture($backend, new AsyncRetryPolicy(maxAttempts: 1));
        $replace = new AsyncEnqueueOptions(key: 'refresh', replace: true);
        $failedId = $f->queue->enqueue($this->envelope('failed'), $replace);
        $delivery = $f->queue->reserve();
        static::assertNotNull($delivery);
        $f->queue->release($delivery, reason: new \RuntimeException('failed'));

        $f->queue->enqueue($this->envelope('first replacement'), $replace);
        static::assertTrue($f->queue->replayFailed($failedId));
        $replacementId = $f->queue->enqueue($this->envelope('final replacement'), $replace);

        $ids = [];
        while (null !== ($delivery = $f->queue->reserve())) {
            $ids[] = $delivery->messageId->value;
            $f->queue->acknowledge($delivery);
        }
        static::assertEqualsCanonicalizing([$failedId->value, $replacementId->value], $ids);
    }

    public function test_in_memory_replayed_message_survives_replacement_of_all_its_former_keys(): void
    {
        $f = $this->fixture(retryPolicy: new AsyncRetryPolicy(maxAttempts: 1));
        $id = new AsyncMessageId('failed');
        $keys = ['refresh', 'rebuild'];
        foreach ($keys as $key) {
            $f->queue->enqueue($this->envelope('failed'), new AsyncEnqueueOptions(messageId: $id, key: $key));
        }
        $delivery = $f->queue->reserve();
        static::assertNotNull($delivery);
        $f->queue->release($delivery);
        static::assertTrue($f->queue->replayFailed($id));

        foreach ($keys as $key) {
            $f->queue->enqueue($this->envelope('replacement'), new AsyncEnqueueOptions(key: $key, replace: true));
        }

        static::assertSame(3, $f->count());
        static::assertSame($id->value, $f->queue->reserve()?->messageId->value);
    }

    public function test_postgres_quarantine_and_replay_are_transactional_and_queue_scoped(): void
    {
        $f = $this->fixture('postgres', new AsyncRetryPolicy(maxAttempts: 1));
        $id = $f->queue->enqueue($this->envelope('first'));
        $delivery = $f->queue->reserve();
        static::assertNotNull($delivery);
        $failure = new \RuntimeException('failed');
        $other = PostgresConnectionSupport::connection(getenv('ULTRA_TEST_POSTGRES_DSN'));
        try {
            $peer = $f->peer($other);
            $unrelated = $f->peer($other, 'other');
            $f->connection->beginTransaction();
            $f->queue->release($delivery, reason: $failure);
            static::assertSame(1, $f->queue->countFailed());
            static::assertSame(0, $peer->countFailed());
            $f->connection->rollBack();
            static::assertSame(0, $f->queue->countFailed());

            $f->queue->release($delivery, reason: $failure);
            static::assertSame(1, $peer->countFailed());
            static::assertSame('failed', $peer->inspectFailed($id)?->failureMessage);
            static::assertSame(0, $unrelated->countFailed());
            static::assertSame([], $unrelated->listFailed());
            static::assertNull($unrelated->inspectFailed($id));
            static::assertFalse($unrelated->replayFailed($id));

            $f->connection->beginTransaction();
            static::assertTrue($f->queue->replayFailed($id));
            static::assertSame(1, $peer->countFailed());
            static::assertNull($peer->reserve());
            $f->connection->rollBack();

            static::assertTrue($peer->replayFailed($id));
            static::assertFalse($f->queue->replayFailed($id));
            static::assertSame(0, $f->queue->countFailed());
            static::assertSame($id->value, $f->queue->reserve()?->messageId->value);
        } finally {
            $other->close();
        }
    }

    public function test_postgres_can_inspect_and_replay_raw_payload_when_decoding_failed(): void
    {
        $f = $this->fixture('postgres', new AsyncRetryPolicy(maxAttempts: 1));
        $envelope = $this->envelope('binary');
        $body = "\x00\xffbinary";
        $codec = $this->createStub(AsyncEnvelopeCodecInterface::class);
        $codec->method('encode')->willReturn(new EncodedAsyncEnvelope($body, $envelope->headers, [
            'traceId' => 'trace-1',
        ]));
        $failure = new \UnexpectedValueException("cannot decode binary\x00\xff");
        $codec->method('decode')->willThrowException($failure);
        $queue = $f->peer($f->connection, codec: $codec);
        $id = $queue->enqueue($envelope);

        try {
            $queue->reserve();
            static::fail('Expected the decoder to fail.');
        } catch (\UnexpectedValueException $caught) {
            static::assertSame($failure, $caught);
        }
        static::assertSame(1, $queue->countFailed());
        $failed = $queue->inspectFailed($id);
        static::assertNotNull($failed);
        static::assertSame(\UnexpectedValueException::class, $failed->failureClass);
        static::assertStringStartsWith('cannot decode binary', $failed->failureMessage);
        static::assertSame($body, $failed->envelope->body);
        static::assertSame(['traceId' => 'trace-1'], $failed->envelope->attributes);

        static::assertTrue($queue->replayFailed($id));
        $workingCodec = $this->createMock(AsyncEnvelopeCodecInterface::class);
        $workingCodec->expects(static::once())->method('decode')->with($body, $envelope->headers, [
            'traceId' => 'trace-1',
        ])->willReturn($envelope);
        $delivery = $f->peer($f->connection, codec: $workingCodec)->reserve();
        static::assertNotNull($delivery);
        static::assertSame($id->value, $delivery->messageId->value);
        static::assertSame(1, $delivery->attempts);
        static::assertSame($envelope, $delivery->envelope);
    }

    private function assertWorkerFailure(QueueWorker $worker, \RuntimeException $failure): void
    {
        try {
            $worker->drain();
            static::fail('Expected the handler to fail.');
        } catch (\RuntimeException $caught) {
            static::assertSame($failure, $caught);
        }
    }
}
