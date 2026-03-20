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
 * Events are stored once in the `events` table, while `event_query_streams` materializes membership in the
 * canonical singleton/pair keys used by supported queries. `load()` is lock-free. `append()` acquires
 * transaction-scoped advisory locks, replays the expected query to compare its last event id, and only then
 * inserts new events.
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
            return new LoadResult([], QueryVersion::empty($query));
        }

        $keys = $canonicalQuery->allKeys();
        $events = [] === $keys ? [] : $this->fetchEvents($keys);
        $lastEventId = [] === $events ? 0 : $events[array_key_last($events)]->eventId;

        return new LoadResult($events, new QueryVersion($lastEventId, $query));
    }

    /**
     * Append events and, when requested, verify the expected query under transaction-scoped advisory locks.
     */
    public function append(array $events, ?QueryVersion $expectedVersion = null): void
    {
        $ownsTransaction = ! $this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $readKeys = [];
            if (null !== $expectedVersion) {
                $readKeys = $this->queryKeyFactory->resolveQuery($expectedVersion->query)->allKeys();
            }

            $eventsWithKeys = [];
            $writeKeys = [];
            foreach ($events as $event) {
                $eventKeys = $this->queryKeyFactory->keysForEvent($event->eventType, $event->domainIds);
                $eventsWithKeys[] = [$event, $eventKeys];
                foreach ($eventKeys as $eventKey) {
                    $writeKeys[$eventKey] = true;
                }
            }

            $this->acquireAdvisoryLocks($readKeys, array_keys($writeKeys));

            if (null !== $expectedVersion) {
                $currentVersion = new QueryVersion(
                    $this->fetchLastEventId($readKeys),
                    $expectedVersion->query,
                );
                $currentVersion->assertMatches($expectedVersion);
            }

            if ([] !== $eventsWithKeys) {
                foreach ($eventsWithKeys as [$event, $queryStreamKeys]) {
                    $eventId = $this->insertEvent($event);
                    $this->insertEventQueryStreams($eventId, $queryStreamKeys);
                }
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
     * Return the last event id visible for the canonical query keys. `0` means no matching events.
     */
    private function fetchLastEventId(array $queryStreamKeys): int
    {
        if ([] === $queryStreamKeys) {
            return 0;
        }

        sort($queryStreamKeys);

        return (int) $this->connection->executeQuery(
            sprintf(
                'SELECT COALESCE(MAX(event_id), 0)
                 FROM %s
                 WHERE query_stream_key IN (?)',
                $this->eventQueryStreamsTable(),
            ),
            [$queryStreamKeys],
            [ArrayParameterType::STRING],
        )->fetchOne();
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

    /**
     * Acquire transaction-scoped advisory locks in deterministic order.
     *
     * Read locks use the exact query keys. Write locks use all canonical singleton/pair subsets produced by
     * the appended events. When a key is present in both sets, the stronger exclusive lock wins.
     *
     * @param list<string> $readKeys
     * @param list<string> $writeKeys
     */
    private function acquireAdvisoryLocks(array $readKeys, array $writeKeys): void
    {
        $modeByKey = [];
        foreach ($readKeys as $readKey) {
            $modeByKey[$readKey] = 'shared';
        }
        foreach ($writeKeys as $writeKey) {
            $modeByKey[$writeKey] = 'exclusive';
        }

        ksort($modeByKey);

        foreach ($modeByKey as $queryStreamKey => $mode) {
            $function = 'exclusive' === $mode ? 'pg_advisory_xact_lock' : 'pg_advisory_xact_lock_shared';
            $this->connection->executeQuery(
                sprintf('SELECT %s(hashtextextended(CAST(? AS text), 0))', $function),
                [$queryStreamKey],
            )->free();
        }
    }

    private function eventsTable(): string
    {
        return $this->tablePrefix.'events';
    }

    private function eventQueryStreamsTable(): string
    {
        return $this->tablePrefix.'event_query_streams';
    }
}
