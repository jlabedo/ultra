<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_PostgresQueue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\MessageHandling\Async\AsyncDelivery;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncHeaders;
use Ultra\MessageHandling\Async\AsyncMessageId;
use Ultra\MessageHandling\Async\AsyncQueueInterface;
use Ultra\Time\ClockInterface;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final readonly class PostgresAsyncQueue implements AsyncQueueInterface
{
    private string $workerId;

    public function __construct(
        private Connection $connection,
        private AsyncEnvelopeCodecInterface $codec,
        private string $queueName = 'default',
        private string $tablePrefix = 'async_queue_',
        private ClockInterface $clock = new SystemClock(),
        ?string $workerId = null,
    ) {
        $this->workerId = $workerId ?? gethostname().'-'.bin2hex(random_bytes(8));
    }

    public function enqueue(AsyncEnvelope $envelope, ?AsyncEnqueueOptions $options = null): AsyncMessageId
    {
        $options ??= new AsyncEnqueueOptions();
        $messageId = $options->messageId ?? AsyncMessageId::generate();
        $encoded = $this->codec->encode($envelope);
        $now = $this->clock->now();
        $availableAt = $options->delay instanceof Duration ? $now->add($options->delay) : $now;

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (
                    id, queue_name, body, headers, attributes, available_at, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, CAST(? AS JSONB), CAST(? AS JSONB), CAST(? AS TIMESTAMPTZ), CAST(? AS TIMESTAMPTZ), CAST(? AS TIMESTAMPTZ)
                )',
                $this->messagesTable(),
            ),
            [
                $messageId->value,
                $this->queueName,
                $encoded->body,
                $this->encodeJsonObject($encoded->headers->all(), 'async headers'),
                $this->encodeJsonObject($encoded->attributes, 'async attributes'),
                $availableAt,
                $now,
                $now,
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );

        return $messageId;
    }

    public function reserve(Duration $leaseDuration = new Duration(seconds: 30)): ?AsyncDelivery
    {
        $now = $this->clock->now();
        $leaseId = bin2hex(random_bytes(16));
        $leasedUntil = $now->add($leaseDuration);

        $row = $this->connection->executeQuery(
            sprintf(
                'WITH next_message AS (
                    SELECT id
                    FROM %s
                    WHERE queue_name = ?
                      AND available_at <= CAST(? AS TIMESTAMPTZ)
                      AND (lease_id IS NULL OR leased_until < CAST(? AS TIMESTAMPTZ))
                    ORDER BY available_at ASC, id ASC
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1
                )
                UPDATE %s q
                SET lease_id = ?,
                    leased_until = CAST(? AS TIMESTAMPTZ),
                    leased_by = ?,
                    attempts = attempts + 1,
                    updated_at = CAST(? AS TIMESTAMPTZ)
                FROM next_message
                WHERE q.id = next_message.id
                RETURNING q.id, q.body, q.headers, q.attributes, q.attempts, q.leased_until',
                $this->messagesTable(),
                $this->messagesTable(),
            ),
            [
                $this->queueName,
                $now,
                $now,
                $leaseId,
                $leasedUntil,
                $this->workerId,
                $now,
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        $headers = AsyncHeaders::fromArray($this->decodeJsonObject($row['headers'], 'async headers'));
        $attributes = $this->decodeJsonObject($row['attributes'], 'async attributes');

        return new AsyncDelivery(
            new AsyncMessageId((string) $row['id']),
            $this->codec->decode($this->normalizeBody($row['body']), $headers, $attributes),
            $leaseId,
            (int) $row['attempts'],
            new DatePoint((string) $row['leased_until']),
        );
    }

    public function acknowledge(AsyncDelivery $delivery): void
    {
        $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE id = ? AND queue_name = ? AND lease_id = ?',
                $this->messagesTable(),
            ),
            [$delivery->messageId->value, $this->queueName, $delivery->leaseId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function release(AsyncDelivery $delivery, ?Duration $delay = null, ?\Throwable $reason = null): void
    {
        $now = $this->clock->now();
        $availableAt = $delay instanceof Duration ? $now->add($delay) : $now;

        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET lease_id = NULL,
                     leased_until = NULL,
                     leased_by = NULL,
                     available_at = CAST(? AS TIMESTAMPTZ),
                     updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE id = ? AND queue_name = ? AND lease_id = ?',
                $this->messagesTable(),
            ),
            [$availableAt, $now, $delivery->messageId->value, $this->queueName, $delivery->leaseId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function renew(AsyncDelivery $delivery, Duration $leaseDuration = new Duration(seconds: 30)): void
    {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET leased_until = CAST(? AS TIMESTAMPTZ),
                     updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE id = ? AND queue_name = ? AND lease_id = ?',
                $this->messagesTable(),
            ),
            [$now->add($leaseDuration), $now, $delivery->messageId->value, $this->queueName, $delivery->leaseId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    private function messagesTable(): string
    {
        return $this->tablePrefix.'messages';
    }

    /**
     * @param array<string, mixed> $value
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
     * @return array<string, mixed>
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
