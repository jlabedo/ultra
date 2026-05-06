<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_PostgresQueue\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Async\AsyncDelivery;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncHeaderNames;
use Ultra\MessageHandling\Async\AsyncHeaders;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling_PostgresQueue\MapperAsyncEnvelopeCodec;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueue;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueueSchema;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

#[CoversClass(PostgresAsyncQueue::class)]
#[CoversClass(PostgresAsyncQueueSchema::class)]
final class PostgresAsyncQueueTest extends TestCase
{
    public function test_message_round_trips_as_binary_body_headers_and_attributes(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $this->withQueue(function (Connection $connection, PostgresAsyncQueue $queue, string $tablePrefix) use ($clock): void {
            $queue->enqueue(new AsyncEnvelope(
                new PostgresQueueTestMessage('hello'),
                MessageContext::fromArray(['traceId' => 'trace-1']),
                AsyncHeaders::empty()->with(AsyncHeaderNames::ROUTE, 'test.subscription'),
            ));

            $row = $connection->executeQuery(sprintf(
                "SELECT body, headers, attributes FROM %s",
                $tablePrefix.'messages',
            ))->fetchAssociative();

            self::assertIsArray($row);
            self::assertSame('{"value":"hello"}', $row['body']);
            self::assertStringContainsString('test.subscription', (string) $row['headers']);
            self::assertStringContainsString('trace-1', (string) $row['attributes']);

            $delivery = $queue->reserve(Duration::seconds(30));

            self::assertInstanceOf(AsyncDelivery::class, $delivery);
            self::assertInstanceOf(PostgresQueueTestMessage::class, $delivery->envelope->message);
            self::assertSame('hello', $delivery->envelope->message->value);
            self::assertSame('trace-1', $delivery->envelope->messageContext->get('traceId'));
            self::assertSame('test.subscription', $delivery->envelope->headers->getString(AsyncHeaderNames::ROUTE));

            $queue->acknowledge($delivery);

            self::assertSame(0, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $tablePrefix.'messages')));
        }, $clock);
    }

    public function test_expired_lease_redelivers_and_stale_ack_does_not_delete_current_delivery(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $this->withQueue(function (Connection $connection, PostgresAsyncQueue $queue, string $tablePrefix) use ($clock): void {
            $queue->enqueue(new AsyncEnvelope(
                new PostgresQueueTestMessage('lease'),
                MessageContext::fromArray([]),
                AsyncHeaders::empty()->with(AsyncHeaderNames::ROUTE, 'test.subscription'),
            ));

            $stale = $queue->reserve(Duration::seconds(10));
            self::assertInstanceOf(AsyncDelivery::class, $stale);
            self::assertNull($queue->reserve(Duration::seconds(10)));

            $clock->sleep(Duration::seconds(11));

            $current = $queue->reserve(Duration::seconds(10));
            self::assertInstanceOf(AsyncDelivery::class, $current);
            self::assertNotSame($stale->leaseId, $current->leaseId);
            self::assertSame(2, $current->attempts);

            $queue->acknowledge($stale);
            self::assertSame(1, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $tablePrefix.'messages')));

            $queue->acknowledge($current);
            self::assertSame(0, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $tablePrefix.'messages')));
        }, $clock);
    }

    public function test_delay_and_release_delay_control_availability(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $this->withQueue(function (Connection $connection, PostgresAsyncQueue $queue, string $tablePrefix) use ($clock): void {
            $queue->enqueue(
                new AsyncEnvelope(
                    new PostgresQueueTestMessage('delayed'),
                    MessageContext::fromArray([]),
                    AsyncHeaders::empty()->with(AsyncHeaderNames::ROUTE, 'test.subscription'),
                ),
                new AsyncEnqueueOptions(delay: Duration::seconds(10)),
            );

            self::assertNull($queue->reserve());

            $clock->sleep(Duration::seconds(10));
            $delivery = $queue->reserve();
            self::assertInstanceOf(AsyncDelivery::class, $delivery);

            $queue->release($delivery, Duration::seconds(20));

            self::assertNull($queue->reserve());

            $clock->sleep(Duration::seconds(20));

            self::assertInstanceOf(AsyncDelivery::class, $queue->reserve());
        }, $clock);
    }

    /**
     * @param callable(Connection, PostgresAsyncQueue, string): void $callback
     */
    private function withQueue(callable $callback, StatickClock $clock): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres async queue integration tests.');
        }

        $connection = PostgresTestSupport::sharedConnection($dsn);
        $tablePrefix = 'async_queue_'.bin2hex(random_bytes(4)).'_';
        $schema = new PostgresAsyncQueueSchema($tablePrefix);

        try {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $queue = new PostgresAsyncQueue(
                $connection,
                new MapperAsyncEnvelopeCodec(new UltraNormalizer()),
                tablePrefix: $tablePrefix,
                clock: $clock,
                workerId: 'test-worker',
            );

            $callback($connection, $queue, $tablePrefix);
        } finally {
            PostgresTestSupport::resetSharedConnection($connection);

            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }
}

final readonly class PostgresQueueTestMessage
{
    public function __construct(public string $value) {}
}
