<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\CanonicalQueryKeyFactory;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventTopologyProvider;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StoredEvent;
use Ultra\EventStore\StreamQuery;

/**
 * PostgreSQL implementation of the event store backed by typed canonical query streams.
 *
 * Events are stored once in the `events` table, while `query_streams` tracks the last event id seen for each
 * canonical key and `event_query_streams` materializes event membership in those keys. `append()` performs
 * optimistic locking by locking the expected `query_streams` rows and comparing them against the supplied
 * `QueryVersion` before inserting new events.
 */
final class PostgresEventStore implements EventStore
{
    private CanonicalQueryKeyFactory $queryKeyFactory;

    public function __construct(
        private readonly Connection $connection,
        EventTopologyProvider $topologyProvider,
        private readonly string $tablePrefix = 'event_store_',
    ) {
        $this->queryKeyFactory = new CanonicalQueryKeyFactory($topologyProvider);
    }

    /**
     * Load and materialize the events matched by a typed canonical query.
     */
    public function load(StreamQuery $query): LoadResult
    {
        $canonicalQuery = $this->queryKeyFactory->resolveQuery($query);
        if ($query->isEmpty()) {
            return new LoadResult([], new QueryVersion([], $query));
        }

        $keys = $canonicalQuery->allKeys();
        $version = $this->fetchVersionMap($keys);
        $events = [] === $keys ? [] : $this->fetchEvents($keys);

        return new LoadResult($events, new QueryVersion($version, $query));
    }

    /**
     * Append events and, when requested, verify the expected canonical query-stream heads under row locks.
     */
    public function append(array $events, ?QueryVersion $expectedVersion = null): void
    {
        $ownsTransaction = ! $this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            if (null !== $expectedVersion) {
                $canonicalQuery = $this->queryKeyFactory->resolveQuery($expectedVersion->query);
                $expectedKeys = $canonicalQuery->allKeys();
                $this->upsertQueryStreams(array_fill_keys($expectedKeys, 0));
                $currentVersion = new QueryVersion(
                    $this->fetchVersionMap($expectedKeys, true),
                    $expectedVersion->query,
                );
                $currentVersion->assertMatches($expectedVersion);
            }

            if ([] !== $events) {
                $lastEventIdByKey = [];
                foreach ($events as $event) {
                    $eventId = $this->insertEvent($event);
                    $queryStreamKeys = $this->queryKeyFactory->keysForEvent($event->eventType, $event->domainIds);
                    $this->upsertQueryStreams(array_fill_keys($queryStreamKeys, 0));
                    $this->insertEventQueryStreams($eventId, $queryStreamKeys);

                    foreach ($queryStreamKeys as $queryStreamKey) {
                        $lastEventIdByKey[$queryStreamKey] = max($lastEventIdByKey[$queryStreamKey] ?? 0, $eventId);
                    }
                }

                $this->upsertQueryStreams($lastEventIdByKey);
            }

            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param list<string> $queryStreamKeys
     *
     * @return array<string, int>
     *
     * When `$forUpdate` is true, the selected query-stream rows are locked in deterministic key order
     */
    private function fetchVersionMap(array $queryStreamKeys, bool $forUpdate = false): array
    {
        if ([] === $queryStreamKeys) {
            return [];
        }

        sort($queryStreamKeys);

        $sql = sprintf(
            'SELECT query_stream_key, last_event_id
             FROM %s
             WHERE query_stream_key IN (?)
             ORDER BY query_stream_key%s',
            $this->queryStreamsTable(),
            $forUpdate ? ' FOR UPDATE' : '',
        );

        $rows = $this->connection->executeQuery(
            $sql,
            [$queryStreamKeys],
            [ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $version = array_fill_keys($queryStreamKeys, 0);
        foreach ($rows as $row) {
            $version[$row['query_stream_key']] = (int) $row['last_event_id'];
        }

        return $version;
    }

    /**
     * @param list<string> $queryStreamKeys
     *
     * @return list<StoredEvent>
     *
     * Events are deduplicated at SQL level because OR queries may resolve to multiple canonical keys
     */
    private function fetchEvents(array $queryStreamKeys): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT DISTINCT e.event_id, e.event_type, e.payload, e.domain_ids, e.recorded_at
                 FROM %1$s e
                 INNER JOIN %2$s eqs ON eqs.event_id = e.event_id
                 WHERE eqs.query_stream_key IN (?)
                 ORDER BY e.event_id ASC',
                $this->eventsTable(),
                $this->eventQueryStreamsTable(),
            ),
            [$queryStreamKeys],
            [ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $events = [];
        foreach ($rows as $row) {
            $events[] = new StoredEvent(
                eventId: (int) $row['event_id'],
                eventType: $row['event_type'],
                payload: json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
                domainIds: json_decode($row['domain_ids'], true, 512, JSON_THROW_ON_ERROR),
                recordedAt: new \DateTimeImmutable($row['recorded_at']),
            );
        }

        return $events;
    }

    private function insertEvent(AppendableEvent $event): int
    {
        $row = $this->connection->executeQuery(
            sprintf(
                'INSERT INTO %s (event_type, payload, domain_ids)
                 VALUES (?, ?::jsonb, ?::jsonb)
                 RETURNING event_id',
                $this->eventsTable(),
            ),
            [
                $event->eventType,
                json_encode($event->payload, JSON_THROW_ON_ERROR),
                json_encode($event->domainIds, JSON_THROW_ON_ERROR),
            ],
        )->fetchAssociative();

        return (int) $row['event_id'];
    }

    /**
     * @param array<string, int> $lastEventIdByKey
     *
     * Ensure query-stream rows exist and advance their heads monotonically
     */
    private function upsertQueryStreams(array $lastEventIdByKey): void
    {
        if ([] === $lastEventIdByKey) {
            return;
        }

        ksort($lastEventIdByKey);
        $placeholders = implode(', ', array_fill(0, count($lastEventIdByKey), '(?, ?)'));
        $params = [];
        foreach ($lastEventIdByKey as $queryStreamKey => $lastEventId) {
            $params[] = $queryStreamKey;
            $params[] = $lastEventId;
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %1$s (query_stream_key, last_event_id)
                 VALUES %2$s
                 ON CONFLICT (query_stream_key) DO UPDATE
                 SET last_event_id = GREATEST(%1$s.last_event_id, EXCLUDED.last_event_id)',
                $this->queryStreamsTable(),
                $placeholders,
            ),
            $params,
        );
    }

    /**
     * @param list<string> $queryStreamKeys
     *
     * Record the canonical query streams an inserted event belongs to
     */
    private function insertEventQueryStreams(int $eventId, array $queryStreamKeys): void
    {
        if ([] === $queryStreamKeys) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($queryStreamKeys), '(?, ?)'));
        $params = [];
        foreach ($queryStreamKeys as $queryStreamKey) {
            $params[] = $eventId;
            $params[] = $queryStreamKey;
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (event_id, query_stream_key) VALUES %s',
                $this->eventQueryStreamsTable(),
                $placeholders,
            ),
            $params,
        );
    }

    private function eventsTable(): string
    {
        return $this->tablePrefix.'events';
    }

    private function queryStreamsTable(): string
    {
        return $this->tablePrefix.'query_streams';
    }

    private function eventQueryStreamsTable(): string
    {
        return $this->tablePrefix.'event_query_streams';
    }
}
