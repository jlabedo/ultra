<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class PostgresProjectionRuntimeRepository
{
    public function __construct(
        private Connection $connection,
        private string $tablePrefix = 'projection_',
    ) {}

    public function lockProjectionState(string $projectionName, int $projectionVersion, string $lane): ProjectionState
    {
        $wasCreated = $this->createProjectionStateIfMissing($projectionName, $projectionVersion, $lane);
        $lastEventId = $wasCreated
            ? 0
            : (int) $this->connection->executeQuery(
                sprintf(
                    'SELECT last_event_id
                     FROM %s
                     WHERE projection_name = ? AND projection_version = ? AND lane = ?
                     FOR UPDATE',
                    $this->stateTable(),
                ),
                [$projectionName, $projectionVersion, $lane],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
            )->fetchOne();

        return new ProjectionState(
            projectionName: $projectionName,
            projectionVersion: $projectionVersion,
            lane: $lane,
            lastEventId: $lastEventId,
            wasCreated: $wasCreated,
        );
    }

    public function advanceProjectionState(ProjectionState $state, int $lastEventId): void
    {
        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET last_event_id = ?, last_error = NULL, updated_at = statement_timestamp()
                 WHERE projection_name = ? AND projection_version = ? AND lane = ?',
                $this->stateTable(),
            ),
            [$lastEventId, $state->projectionName, $state->projectionVersion, $state->lane],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    public function recordProjectionError(ProjectionState $state, \Throwable $exception): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (projection_name, projection_version, lane, last_error)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (projection_name, projection_version, lane)
                 DO UPDATE SET
                     last_error = EXCLUDED.last_error,
                     updated_at = statement_timestamp()',
                $this->stateTable(),
            ),
            [$state->projectionName, $state->projectionVersion, $state->lane, $this->formatError($exception)],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function createPartitionStateIfMissing(string $projectionName, int $projectionVersion, string $partitionKey): bool
    {
        return 1 === $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (projection_name, projection_version, partition_key)
                 VALUES (?, ?, ?)
                 ON CONFLICT (projection_name, projection_version, partition_key) DO NOTHING',
                $this->partitionsTable(),
            ),
            [$projectionName, $projectionVersion, $partitionKey],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    public function enqueueEvents(string $projectionName, int $projectionVersion, array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?)'));
        $params = [];
        $types = [];

        foreach ($rows as [$partitionKey, $eventId]) {
            $params[] = $projectionName;
            $types[] = ParameterType::STRING;
            $params[] = $projectionVersion;
            $types[] = ParameterType::INTEGER;
            $params[] = $partitionKey;
            $types[] = ParameterType::STRING;
            $params[] = $eventId;
            $types[] = ParameterType::INTEGER;
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (projection_name, projection_version, partition_key, event_id)
                 VALUES %s
                 ON CONFLICT (projection_name, projection_version, partition_key, event_id) DO NOTHING',
                $this->inboxTable(),
                $placeholders,
            ),
            $params,
            $types,
        );
    }

    public function claimNextPartition(string $projectionName, int $projectionVersion): ?PartitionState
    {
        $row = $this->connection->executeQuery(
            sprintf(
                'SELECT p.partition_key, p.last_event_id
                 FROM %1$s p
                 WHERE p.projection_name = ?
                   AND p.projection_version = ?
                   AND EXISTS (
                       SELECT 1
                       FROM %2$s i
                       WHERE i.projection_name = p.projection_name
                         AND i.projection_version = p.projection_version
                         AND i.partition_key = p.partition_key
                         AND i.event_id > p.last_event_id
                   )
                 ORDER BY p.last_event_id ASC, p.partition_key ASC
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1',
                $this->partitionsTable(),
                $this->inboxTable(),
            ),
            [$projectionName, $projectionVersion],
            [ParameterType::STRING, ParameterType::INTEGER],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return new PartitionState(
            partitionKey: $row['partition_key'],
            lastEventId: (int) $row['last_event_id'],
        );
    }

    /**
     * @return list<int>
     */
    public function pendingEventIds(string $projectionName, int $projectionVersion, string $partitionKey, int $afterEventId, int $limit): array
    {
        $eventIds = $this->connection->executeQuery(
            sprintf(
                'SELECT event_id
                 FROM %s
                 WHERE projection_name = ?
                   AND projection_version = ?
                   AND partition_key = ?
                   AND event_id > ?
                 ORDER BY event_id ASC
                 LIMIT ?',
                $this->inboxTable(),
            ),
            [$projectionName, $projectionVersion, $partitionKey, $afterEventId, $limit],
            [
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
            ],
        )->fetchFirstColumn();

        return array_map(static fn (mixed $eventId): int => (int) $eventId, $eventIds);
    }

    public function advancePartitionState(string $projectionName, int $projectionVersion, string $partitionKey, int $lastEventId): void
    {
        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET last_event_id = ?, last_error = NULL, updated_at = statement_timestamp()
                 WHERE projection_name = ? AND projection_version = ? AND partition_key = ?',
                $this->partitionsTable(),
            ),
            [$lastEventId, $projectionName, $projectionVersion, $partitionKey],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    public function deleteInboxThrough(string $projectionName, int $projectionVersion, string $partitionKey, int $lastEventId): void
    {
        $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s
                 WHERE projection_name = ?
                   AND projection_version = ?
                   AND partition_key = ?
                   AND event_id <= ?',
                $this->inboxTable(),
            ),
            [$projectionName, $projectionVersion, $partitionKey, $lastEventId],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    public function recordPartitionError(string $projectionName, int $projectionVersion, string $partitionKey, \Throwable $exception): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (projection_name, projection_version, partition_key, last_error)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (projection_name, projection_version, partition_key)
                 DO UPDATE SET
                     last_error = EXCLUDED.last_error,
                     updated_at = statement_timestamp()',
                $this->partitionsTable(),
            ),
            [$projectionName, $projectionVersion, $partitionKey, $this->formatError($exception)],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );
    }

    /**
     * @return array{last_event_id: int, last_error: ?string}|null
     */
    public function fetchStateRow(string $projectionName, int $projectionVersion, string $lane): ?array
    {
        $row = $this->connection->executeQuery(
            sprintf(
                'SELECT last_event_id, last_error
                 FROM %s
                 WHERE projection_name = ? AND projection_version = ? AND lane = ?',
                $this->stateTable(),
            ),
            [$projectionName, $projectionVersion, $lane],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return [
            'last_event_id' => (int) $row['last_event_id'],
            'last_error' => null === $row['last_error'] ? null : (string) $row['last_error'],
        ];
    }

    /**
     * @return array{partition_key: string, last_event_id: int, last_error: ?string}|null
     */
    public function fetchPartitionRow(string $projectionName, int $projectionVersion, string $partitionKey): ?array
    {
        $row = $this->connection->executeQuery(
            sprintf(
                'SELECT partition_key, last_event_id, last_error
                 FROM %s
                 WHERE projection_name = ? AND projection_version = ? AND partition_key = ?',
                $this->partitionsTable(),
            ),
            [$projectionName, $projectionVersion, $partitionKey],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return [
            'partition_key' => (string) $row['partition_key'],
            'last_event_id' => (int) $row['last_event_id'],
            'last_error' => null === $row['last_error'] ? null : (string) $row['last_error'],
        ];
    }

    /**
     * @return list<array{partition_key: string, event_id: int}>
     */
    public function fetchInboxRows(string $projectionName, int $projectionVersion): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT partition_key, event_id
                 FROM %s
                 WHERE projection_name = ? AND projection_version = ?
                 ORDER BY partition_key ASC, event_id ASC',
                $this->inboxTable(),
            ),
            [$projectionName, $projectionVersion],
            [ParameterType::STRING, ParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'partition_key' => (string) $row['partition_key'],
            'event_id' => (int) $row['event_id'],
        ], $rows);
    }

    private function createProjectionStateIfMissing(string $projectionName, int $projectionVersion, string $lane): bool
    {
        return 1 === $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (projection_name, projection_version, lane)
                 VALUES (?, ?, ?)
                 ON CONFLICT (projection_name, projection_version, lane) DO NOTHING',
                $this->stateTable(),
            ),
            [$projectionName, $projectionVersion, $lane],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    private function formatError(\Throwable $exception): string
    {
        return sprintf('%s: %s', $exception::class, $exception->getMessage());
    }

    private function stateTable(): string
    {
        return $this->tablePrefix.'state';
    }

    private function partitionsTable(): string
    {
        return $this->tablePrefix.'partitions';
    }

    private function inboxTable(): string
    {
        return $this->tablePrefix.'inbox';
    }
}
