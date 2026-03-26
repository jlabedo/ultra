<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\EventStore\StoredEvent;
use Ultra\Projection\EventFeed;

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
        if ($limit < 1) {
            throw new \InvalidArgumentException('Feed batch size must be at least 1.');
        }

        $sql = sprintf(
            'SELECT event_id, event_type, payload, %1$s AS domain_ids, recorded_at
             FROM %2$s
             WHERE event_id > ?',
            $this->domainIdsExpression,
            $this->eventsTable(),
        );
        $params = [$afterEventId];
        $types = [ParameterType::INTEGER];

        if ([] !== $eventTypes) {
            $sql .= ' AND event_type IN (?)';
            $params[] = array_values($eventTypes);
            $types[] = ArrayParameterType::STRING;
        }

        $sql .= ' ORDER BY event_id ASC LIMIT ?';
        $params[] = $limit;
        $types[] = ParameterType::INTEGER;

        return $this->mapRows($this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative());
    }

    public function readByIds(array $eventIds): array
    {
        if ([] === $eventIds) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT event_id, event_type, payload, %1$s AS domain_ids, recorded_at
                 FROM %2$s
                 WHERE event_id IN (?)
                 ORDER BY event_id ASC',
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
}
