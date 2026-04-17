<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Sequence;
use Ultra\Projection\EventFeed;
use Ultra\Projection\EventFeedResult;
use Ultra\Projection\StoredEvent;

final readonly class PostgresEventFeed implements EventFeed
{
    private function __construct(
        private Connection $connection,
        private string $tablePrefix,
        private string $domainIdsExpression,
    ) {}

    public static function forJsonb(Connection $connection, string $tablePrefix = 'event_store_'): self
    {
        return new self($connection, $tablePrefix, 'domain_ids');
    }

    public static function forHstore(Connection $connection, string $tablePrefix = 'event_store_'): self
    {
        return new self($connection, $tablePrefix, 'hstore_to_json(domain_ids)');
    }

    public function readAfter(int $afterEventId, int $limit, array $eventTypes = []): array
    {
        return $this->read(Checkpoint::at(new Sequence($afterEventId)), $limit, $eventTypes)->events;
    }

    public function read(?Checkpoint $from, int $limit, array $eventTypes = []): EventFeedResult
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Feed batch size must be at least 1.');
        }

        $sql = sprintf(
            'WITH snap AS (SELECT pg_current_snapshot() AS s)
             SELECT sequence AS event_id,
                    CASE WHEN xid >= pg_snapshot_xmin(snap.s) THEN 1 ELSE 0 END AS in_gap,
                    event_type,
                    payload,
                    %1$s AS domain_ids,
                    recorded_at
             FROM %2$s, snap
             WHERE sequence > ?',
            $this->domainIdsExpression,
            $this->eventsTable(),
        );
        $params = [$from?->gapFloor->value ?? 0];
        $types = [ParameterType::INTEGER];

        $gapSeqNos = $from?->gapSeqNoValues() ?? [];
        if ([] !== $gapSeqNos) {
            $sql .= ' AND sequence NOT IN (?)';
            $params[] = $gapSeqNos;
            $types[] = ArrayParameterType::INTEGER;
        }

        if ([] !== $eventTypes) {
            $sql .= ' AND event_type IN (?)';
            $params[] = array_values($eventTypes);
            $types[] = ArrayParameterType::STRING;
        }

        $sql .= ' ORDER BY sequence ASC LIMIT ?';
        $params[] = $limit;
        $types[] = ParameterType::INTEGER;

        $rows = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return new EventFeedResult(
            $this->mapRows($rows),
            $this->captureCheckpoint($from, $rows),
        );
    }

    public function readByIds(array $eventIds): array
    {
        if ([] === $eventIds) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT sequence AS event_id, event_type, payload, %1$s AS domain_ids, recorded_at
                 FROM %2$s
                 WHERE sequence IN (?)
                 ORDER BY sequence ASC',
                $this->domainIdsExpression,
                $this->eventsTable(),
            ),
            [array_values($eventIds)],
            [ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        return $this->mapRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<StoredEvent>
     */
    private function mapRows(array $rows): array
    {
        $events = [];

        foreach ($rows as $row) {
            $events[] = new StoredEvent(
                eventId: (int) $row['event_id'],
                eventType: (string) $row['event_type'],
                payload: json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR),
                domainIds: json_decode((string) $row['domain_ids'], true, 512, JSON_THROW_ON_ERROR),
                recordedAt: new \DateTimeImmutable((string) $row['recorded_at']),
            );
        }

        return $events;
    }

    private function eventsTable(): string
    {
        return $this->tablePrefix.'events';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function captureCheckpoint(?Checkpoint $from, array $rows): Checkpoint
    {
        $checkpoint = $from ?? Checkpoint::beginning();

        foreach ($rows as $row) {
            $checkpoint = $checkpoint->record(
                new Sequence((int) $row['event_id']),
                1 === (int) $row['in_gap'],
            );
        }

        return $checkpoint;
    }
}
