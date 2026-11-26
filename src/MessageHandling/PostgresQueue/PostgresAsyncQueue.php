<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_PostgresQueue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\MessageHandling\Async\AsyncDelivery;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncHeaders;
use Ultra\MessageHandling\Async\AsyncMessageId;
use Ultra\MessageHandling\Async\AsyncMessageReceiver;
use Ultra\MessageHandling\Async\AsyncMessageSender;
use Ultra\MessageHandling\Async\AsyncRetryPolicy;
use Ultra\MessageHandling\Async\DeadLetter;
use Ultra\MessageHandling\Async\DeadLetterQueue;
use Ultra\Time\ClockInterface;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

/** @implements DeadLetterQueue<EncodedAsyncEnvelope> */
final readonly class PostgresAsyncQueue implements AsyncMessageSender, AsyncMessageReceiver, DeadLetterQueue
{
    public const string DEFAULT_TABLE_PREFIX = 'async_queue_';

    private string $workerId;

    public function __construct(
        private Connection $connection,
        private AsyncEnvelopeCodecInterface $codec,
        private string $queueName = 'default',
        private string $tablePrefix = self::DEFAULT_TABLE_PREFIX,
        private ClockInterface $clock = new SystemClock(),
        ?string $workerId = null,
        private AsyncRetryPolicy $retryPolicy = new AsyncRetryPolicy(),
    ) {
        $this->workerId = $workerId ?? (string) gethostname() . '-' . bin2hex(random_bytes(8));
    }

    public function enqueue(AsyncEnvelope $envelope, ?AsyncEnqueueOptions $options = null): AsyncMessageId
    {
        $options ??= new AsyncEnqueueOptions();
        $messageId = $options->messageId ?? AsyncMessageId::generate();
        $encoded = $this->codec->encode($envelope);
        $now = $this->clock->now();
        $availableAt =
            $options->availableAt ?? ($options->delay instanceof Duration ? $now->add($options->delay) : $now);

        $this->insertMessage($messageId, $encoded, $options->key, $availableAt, $now, $options->replace);

        return $messageId;
    }

    private function insertMessage(
        AsyncMessageId $messageId,
        EncodedAsyncEnvelope $encoded,
        ?string $key,
        DatePoint $availableAt,
        DatePoint $now,
        bool $replace,
    ): void {
        $table = $this->messagesTable();
        $insert = "INSERT INTO {$table} (
            id, queue_name, body, headers, attributes, schedule_key, available_at, created_at, updated_at
        )";
        $values = '?, ?, ?, CAST(? AS JSONB), CAST(? AS JSONB), ?,
            CAST(? AS TIMESTAMPTZ), CAST(? AS TIMESTAMPTZ), CAST(? AS TIMESTAMPTZ)';
        $parameters = [
            $messageId->value,
            $this->queueName,
            $encoded->body,
            $this->encodeJsonObject($encoded->headers->all(), 'async headers'),
            $this->encodeJsonObject($encoded->attributes, 'async attributes'),
            $key,
            $availableAt,
            $now,
            $now,
        ];
        $types = [
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::BINARY,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
        ];
        if (!$replace) {
            $this->connection->executeStatement("{$insert} VALUES ({$values})", $parameters, $types);

            return;
        }

        // Consume the complete DELETE result before inserting so the replacement can reuse a deleted ID.
        $sql = "WITH removed AS (
            DELETE FROM {$table} WHERE queue_name = ? AND schedule_key = ? AND failed_at IS NULL
            RETURNING 1
        )
        {$insert}
        SELECT {$values}
        WHERE (SELECT count(*) FROM removed) >= 0";
        $parameters = [$this->queueName, $key, ...$parameters];
        $types = [ParameterType::STRING, ParameterType::STRING, ...$types];

        // A failed replacement must leave the caller's transaction usable.
        if (!$this->connection->isAutoCommit() || $this->connection->isTransactionActive()) {
            $this->connection->transactional(function () use ($sql, $parameters, $types): void {
                $this->connection->executeStatement($sql, $parameters, $types);
            });

            return;
        }

        $this->connection->executeStatement($sql, $parameters, $types);
    }

    public function reserve(Duration $leaseDuration = new Duration(seconds: 30)): ?AsyncDelivery
    {
        $leaseId = bin2hex(random_bytes(16));

        do {
            $row = $this->leaseNextMessage($leaseId, $leaseDuration);
        } while (false !== $row && null !== $row['failed_at']);

        if (false === $row) {
            return null;
        }

        $messageId = new AsyncMessageId((string) $row['id']);
        try {
            $encoded = $this->readEncodedEnvelope($row);
            $envelope = $this->codec->decode($encoded->body, $encoded->headers, $encoded->attributes);

            return new AsyncDelivery(
                $messageId,
                $envelope,
                $leaseId,
                (int) $row['attempts'],
                new DatePoint((string) $row['leased_until']),
            );
        } catch (\Throwable $exception) {
            $this->releaseLease($messageId, $leaseId, null, $exception);

            throw $exception;
        }
    }

    public function acknowledge(AsyncDelivery $delivery): void
    {
        $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE id = ? AND queue_name = ? AND lease_id = ? AND failed_at IS NULL',
                $this->messagesTable(),
            ),
            [$delivery->messageId->value, $this->queueName, $delivery->leaseId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function release(AsyncDelivery $delivery, ?Duration $delay = null, ?\Throwable $reason = null): void
    {
        $this->releaseLease($delivery->messageId, $delivery->leaseId, $delay, $reason);
    }

    public function renew(AsyncDelivery $delivery, Duration $leaseDuration = new Duration(seconds: 30)): void
    {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET leased_until = CAST(? AS TIMESTAMPTZ),
                     updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE id = ? AND queue_name = ? AND lease_id = ? AND failed_at IS NULL',
                $this->messagesTable(),
            ),
            [$now->add($leaseDuration), $now, $delivery->messageId->value, $this->queueName, $delivery->leaseId],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    public function countFailed(): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE queue_name = ? AND failed_at IS NOT NULL', $this->messagesTable()),
            [$this->queueName],
            [ParameterType::STRING],
        );
    }

    /** @return list<DeadLetter<EncodedAsyncEnvelope>> */
    public function listFailed(int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $offset < 0) {
            throw new \InvalidArgumentException('Dead-letter limit must be positive and offset must be non-negative.');
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT id, body, headers, attributes, attempts, failed_at, failure_class, failure_message
                 FROM %s
                 WHERE queue_name = ? AND failed_at IS NOT NULL
                 ORDER BY failed_at ASC, id ASC
                 LIMIT ? OFFSET ?', $this->messagesTable()),
            [$this->queueName, $limit, $offset],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return array_map($this->readDeadLetter(...), $rows);
    }

    /** @return DeadLetter<EncodedAsyncEnvelope>|null */
    public function inspectFailed(AsyncMessageId $messageId): ?DeadLetter
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT id, body, headers, attributes, attempts, failed_at, failure_class, failure_message
                 FROM %s
                 WHERE queue_name = ? AND id = ? AND failed_at IS NOT NULL',
                $this->messagesTable(),
            ),
            [$this->queueName, $messageId->value],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return false === $row ? null : $this->readDeadLetter($row);
    }

    public function replayFailed(AsyncMessageId $messageId): bool
    {
        $now = $this->clock->now();

        return 1 === (int) $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET failed_at = NULL,
                     failure_class = NULL,
                     failure_message = NULL,
                     schedule_key = NULL,
                     attempts = 0,
                     available_at = CAST(? AS TIMESTAMPTZ),
                     updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE queue_name = ? AND id = ? AND failed_at IS NOT NULL',
                $this->messagesTable(),
            ),
            [$now, $now, $this->queueName, $messageId->value],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    /** @return array<string, mixed>|false */
    private function leaseNextMessage(string $leaseId, Duration $leaseDuration): array|false
    {
        // The selected row is locked before either granting a new lease or quarantining an exhausted delivery.
        $now = $this->clock->now();

        return $this->connection->fetchAssociative(
            sprintf(
                "WITH next_message AS (
                    SELECT id, attempts >= ? AS exhausted
                    FROM %s
                    WHERE queue_name = ?
                      AND failed_at IS NULL
                      AND available_at <= CAST(? AS TIMESTAMPTZ)
                      AND (lease_id IS NULL OR leased_until < CAST(? AS TIMESTAMPTZ))
                    ORDER BY available_at ASC, id ASC
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1
                )
                UPDATE %s q
                SET lease_id = CASE WHEN next_message.exhausted THEN NULL ELSE ? END,
                    leased_until = CASE WHEN next_message.exhausted THEN NULL ELSE CAST(? AS TIMESTAMPTZ) END,
                    leased_by = CASE WHEN next_message.exhausted THEN NULL ELSE ? END,
                    attempts = CASE WHEN next_message.exhausted THEN q.attempts ELSE q.attempts + 1 END,
                    failed_at = CASE WHEN next_message.exhausted THEN CAST(? AS TIMESTAMPTZ) END,
                    failure_message = CASE WHEN next_message.exhausted THEN
                        CASE WHEN q.lease_id IS NULL THEN 'Delivery attempt limit was reached.'
                        ELSE 'Delivery lease expired after its final attempt.' END
                    END,
                    updated_at = CAST(? AS TIMESTAMPTZ)
                FROM next_message
                WHERE q.id = next_message.id
                RETURNING q.id, q.attempts, q.leased_until, q.failed_at,
                    CASE WHEN NOT next_message.exhausted THEN q.body END AS body,
                    CASE WHEN NOT next_message.exhausted THEN q.headers END AS headers,
                    CASE WHEN NOT next_message.exhausted THEN q.attributes END AS attributes",
                $this->messagesTable(),
                $this->messagesTable(),
            ),
            [
                $this->retryPolicy->maxAttempts,
                $this->queueName,
                $now,
                $now,
                $leaseId,
                $now->add($leaseDuration),
                $this->workerId,
                $now,
                $now,
            ],
            [
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    private function releaseLease(
        AsyncMessageId $messageId,
        string $leaseId,
        ?Duration $delay,
        ?\Throwable $reason,
    ): void {
        $now = $this->clock->now();
        if (null !== $reason) {
            $delay ??= $this->retryPolicy->retryDelay;
        }
        $availableAt = null !== $delay ? $now->add($delay) : $now;
        $failureClass = null !== $reason ? $this->postgresText($reason::class) : null;
        $failureMessage = null !== $reason
            ? $this->postgresText($reason->getMessage())
            : 'Delivery was released after its final attempt.';

        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET lease_id = NULL,
                     leased_until = NULL,
                     leased_by = NULL,
                     available_at = CAST(? AS TIMESTAMPTZ),
                     updated_at = release.at,
                     failed_at = CASE WHEN attempts >= release.max_attempts THEN release.at END,
                     failure_class = CASE WHEN attempts >= release.max_attempts THEN ? END,
                     failure_message = CASE WHEN attempts >= release.max_attempts THEN ? END
                 FROM (VALUES (CAST(? AS TIMESTAMPTZ), CAST(? AS INTEGER))) AS release(at, max_attempts)
                 WHERE id = ? AND queue_name = ? AND lease_id = ? AND failed_at IS NULL',
                $this->messagesTable(),
            ),
            [
                $availableAt,
                $failureClass,
                $failureMessage,
                $now,
                $this->retryPolicy->maxAttempts,
                $messageId->value,
                $this->queueName,
                $leaseId,
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return DeadLetter<EncodedAsyncEnvelope>
     */
    private function readDeadLetter(array $row): DeadLetter
    {
        return new DeadLetter(
            new AsyncMessageId((string) $row['id']),
            $this->readEncodedEnvelope($row),
            (int) $row['attempts'],
            new DatePoint((string) $row['failed_at']),
            null === $row['failure_class'] ? null : (string) $row['failure_class'],
            (string) $row['failure_message'],
        );
    }

    /** @param array<string, mixed> $row */
    private function readEncodedEnvelope(array $row): EncodedAsyncEnvelope
    {
        return new EncodedAsyncEnvelope(
            $this->normalizeBody($row['body']),
            AsyncHeaders::fromArray($this->decodeJsonObject($row['headers'], 'async headers')),
            $this->decodeJsonObject($row['attributes'], 'async attributes'),
        );
    }

    private function postgresText(string $text): string
    {
        $text = str_replace("\0", '\0', $text);
        if (1 === preg_match('//u', $text)) {
            return $text;
        }

        /** @var string */
        return json_decode(
            json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function messagesTable(): string
    {
        return $this->tablePrefix . 'messages';
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function encodeJsonObject(array $value, string $description): string
    {
        if (!$this->isJsonObject($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a JSON object.', ucfirst($description)));
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Unable to encode %s.', $description), previous: $e);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJsonObject(mixed $json, string $description): array
    {
        if (!is_string($json)) {
            throw new \RuntimeException(sprintf('Postgres returned non-string %s JSON.', $description));
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Unable to decode %s.', $description), previous: $e);
        }

        if (!is_array($decoded) || !$this->isJsonObject($decoded)) {
            throw new \RuntimeException(sprintf('Decoded %s must be a JSON object.', $description));
        }

        return $decoded;
    }

    private function normalizeBody(mixed $body): string
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body);
            if (false === $contents) {
                throw new \RuntimeException('Unable to read async message body stream.');
            }

            return $contents;
        }

        if (!is_string($body)) {
            throw new \RuntimeException('Postgres returned non-string async message body.');
        }

        return $body;
    }

    /**
     * @param array<mixed> $value
     */
    private function isJsonObject(array $value): bool
    {
        return [] === $value || !array_is_list($value);
    }
}
