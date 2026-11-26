<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Async\AsyncRetryPolicy;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling_PostgresQueue\AsyncEnvelopeCodecInterface;
use Ultra\MessageHandling_PostgresQueue\MapperAsyncEnvelopeCodec;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueue;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueueSchema;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Time\StatickClock;

final readonly class QueueFixture
{
    public InMemoryQueue|PostgresAsyncQueue $queue;
    public string $tablePrefix;

    public function __construct(
        public StatickClock $clock,
        public ?Connection $connection = null,
        private AsyncRetryPolicy $retryPolicy = new AsyncRetryPolicy(),
    ) {
        $this->tablePrefix = 'message_guarantees_' . bin2hex(random_bytes(4)) . '_';
        if (null === $connection) {
            $this->queue = new InMemoryQueue($clock, retryPolicy: $this->retryPolicy);
            return;
        }
        foreach (new PostgresAsyncQueueSchema($this->tablePrefix)->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }
        $this->queue = $this->peer($connection);
    }

    public function peer(
        Connection $connection,
        string $queueName = 'default',
        ?AsyncEnvelopeCodecInterface $codec = null,
    ): PostgresAsyncQueue {
        return new PostgresAsyncQueue(
            $connection,
            $codec ?? new MapperAsyncEnvelopeCodec(new UltraNormalizer()),
            queueName: $queueName,
            tablePrefix: $this->tablePrefix,
            clock: $this->clock,
            workerId: 'test-worker',
            retryPolicy: $this->retryPolicy,
        );
    }

    public function count(): int
    {
        return null === $this->connection
            ? $this->queue->count()
            : (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->tablePrefix . 'messages');
    }

    public function close(): void
    {
        if (null === $this->connection)
            return;
        PostgresConnectionSupport::resetSharedConnection($this->connection);
        foreach (new PostgresAsyncQueueSchema($this->tablePrefix)->dropStatements() as $statement) {
            $this->connection->executeStatement($statement);
        }
    }
}
