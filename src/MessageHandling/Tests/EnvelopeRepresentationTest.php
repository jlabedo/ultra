<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\Async\AsyncHeaderNames;
use Ultra\MessageHandling\Tests\Support\QueueMessage;
use Ultra\MessageHandling\Tests\Support\QueueTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class EnvelopeRepresentationTest extends QueueTestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function contexts(): iterable
    {
        yield 'empty propagated context' => [[]];
        yield 'populated propagated context' => [['traceId' => 'trace-1']];
    }

    #[DataProvider('contexts')]
    public function test_persistent_delivery_round_trips_the_body_headers_and_propagated_context(array $attributes): void
    {
        $f = $this->fixture('postgres');
        $envelope = $this->envelope('hello');
        $f->queue->enqueue(new AsyncEnvelope($envelope->message, MessageContext::fromArray($attributes)->withLocal('runtime', new \stdClass()), $envelope->headers));
        $row = $f->connection->fetchAssociative("SELECT convert_from(body, 'UTF8') AS body, headers, attributes FROM ".$f->tablePrefix.'messages');
        self::assertIsArray($row);
        self::assertSame('{"value":"hello"}', $row['body']);
        self::assertStringContainsString('test.route', $row['headers']);
        self::assertSame($attributes, json_decode($row['attributes'], true, flags: JSON_THROW_ON_ERROR));

        $delivery = $f->queue->reserve();
        self::assertNotNull($delivery);
        self::assertInstanceOf(QueueMessage::class, $delivery->envelope->message);
        self::assertSame('hello', $delivery->envelope->message->value);
        self::assertSame($attributes, $delivery->envelope->messageContext->all());
        self::assertFalse($delivery->envelope->messageContext->has('.runtime'));
        self::assertSame('test.route', $delivery->envelope->headers->getString(AsyncHeaderNames::ROUTE));
        self::assertSame('application/json', $delivery->envelope->headers->getString(AsyncHeaderNames::CONTENT_TYPE));
        self::assertSame(QueueMessage::class, $delivery->envelope->headers->getString(AsyncHeaderNames::MESSAGE_TYPE));
        $f->queue->acknowledge($delivery);
        self::assertSame(0, $f->count());
    }

}
