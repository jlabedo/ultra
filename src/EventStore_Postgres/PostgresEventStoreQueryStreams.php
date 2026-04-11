<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ultra\EventStore\CanonicalQueryKeyFactory;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventTopologyProvider;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StreamQuery;

/**
 * PostgreSQL event store implementation backed by a materialized `event_query_streams` table.
 *
 * Events are stored once in the `events` table, while `event_query_streams` materializes membership in the
 * canonical singleton/pair keys used by supported queries. Those query-stream keys are persisted as fixed-width
 * binary SHA-256 digests to keep the table and index entries compact. `load()` is lock-free. `append()`
 * acquires transaction-scoped advisory locks, replays each expected query to compare its version, and
 * only then inserts new events.
 */
final class PostgresEventStoreQueryStreams implements EventStore
{
    private CanonicalQueryKeyFactory $queryKeyFactory;

    public function __construct(
        private readonly Connection $connection,
        EventTopologyProvider $topologyProvider,
        private readonly string $tablePrefix = 'event_store_',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->queryKeyFactory = new CanonicalQueryKeyFactory($topologyProvider);
    }

    public function load(StreamQuery $query): LoadResult
    {
        $canonicalQuery = $this->queryKeyFactory->resolveQuery($query);
        if ($query->isEmpty()) {
            return new LoadResult([], QueryVersion::empty($query));
        }

        $keys = $canonicalQuery->allKeys();
        $events = [] === $keys ? [] : $this->fetchEvents($keys);

        return new LoadResult($events, new QueryVersion(count($events), $query));
    }

    public function append(array $events, QueryVersion ...$expectedVersions): void
    {
        $ownsTransaction = ! $this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $readKeys = [];
            $expectedQueries = [];
            foreach ($expectedVersions as $expectedVersion) {
                $expectedReadKeys = $this->queryKeyFactory->resolveQuery($expectedVersion->query)->allKeys();
                $expectedQueries[] = [$expectedVersion, $expectedReadKeys];
                foreach ($expectedReadKeys as $readKey) {
                    $readKeys[$readKey] = true;
                }
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

            $this->acquireAdvisoryLocks(array_keys($readKeys), array_keys($writeKeys));

            foreach ($expectedQueries as [$expectedVersion, $expectedReadKeys]) {
                $currentVersion = new QueryVersion($this->fetchQueryVersion($expectedReadKeys), $expectedVersion->query);
                $currentVersion->assertMatches($expectedVersion);
            }

            if ([] !== $eventsWithKeys) {
                $eventIds = $this->insertEvents(array_map(
                    static fn (array $eventWithKeys): Event => $eventWithKeys[0],
                    $eventsWithKeys,
                ));

                $queryStreamRows = [];
                foreach ($eventsWithKeys as $index => [$event, $queryStreamKeys]) {
                    $eventId = $eventIds[$index];
                    foreach ($queryStreamKeys as $queryStreamKey) {
                        $queryStreamRows[] = [$eventId, $this->storageKeyForQueryStream($queryStreamKey)];
                    }
                }

                $this->insertEventQueryStreams($queryStreamRows);
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
     */
    private function fetchQueryVersion(array $queryStreamKeys): int
    {
        if ([] === $queryStreamKeys) {
            return 0;
        }

        return (int) $this->connection->executeQuery(
            sprintf(
                'SELECT COUNT(DISTINCT event_id)
                 FROM %s
                 WHERE query_stream_key IN (?)',
                $this->eventQueryStreamsTable(),
            ),
            [$this->storageKeysForQueryStreams($queryStreamKeys)],
            [ArrayParameterType::BINARY],
        )->fetchOne();
    }

    /**
     * @param list<string> $queryStreamKeys
     *
     * @return list<Event>
     */
    private function fetchEvents(array $queryStreamKeys): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT e.event_id, e.event_type, e.payload, e.domain_ids, e.recorded_at
                 FROM %1$s e
                 WHERE e.event_id IN (
                     SELECT event_id
                     FROM %2$s
                     WHERE query_stream_key IN (?)
                 )
                 ORDER BY e.event_id ASC',
                $this->eventsTable(),
                $this->eventQueryStreamsTable(),
            ),
            [$this->storageKeysForQueryStreams($queryStreamKeys)],
            [ArrayParameterType::BINARY],
        )->fetchAllAssociative();

        $events = [];
        foreach ($rows as $row) {
            $events[] = new Event(
                eventType: $row['event_type'],
                payload: json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
                domainIds: json_decode($row['domain_ids'], true, 512, JSON_THROW_ON_ERROR),
                recordedAt: new \DateTimeImmutable($row['recorded_at']),
            );
        }

        return $events;
    }

    /**
     * @param list<Event> $events
     *
     * @return list<int>
     */
    private function insertEvents(array $events): array
    {
        if ([] === $events) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($events), '(?, ?::jsonb, ?::jsonb)'));
        $params = [];
        $types = [];
        foreach ($events as $event) {
            $params[] = $event->eventType;
            $types[] = ParameterType::STRING;
            $params[] = json_encode($event->payload, JSON_THROW_ON_ERROR);
            $types[] = ParameterType::STRING;
            $params[] = json_encode($event->domainIds, JSON_THROW_ON_ERROR);
            $types[] = ParameterType::STRING;
        }

        $eventIds = $this->connection->executeQuery(
            sprintf(
                'INSERT INTO %1$s (event_type, payload, domain_ids) VALUES %2$s RETURNING event_id',
                $this->eventsTable(),
                $placeholders,
            ),
            $params,
            $types,
        )->fetchFirstColumn();

        return array_map(static fn (mixed $eventId): int => (int) $eventId, $eventIds);
    }

    /**
     * @param list<array{0: int, 1: string}> $rows
     */
    private function insertEventQueryStreams(array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?::bytea)'));
        $params = [];
        $types = [];
        foreach ($rows as [$eventId, $queryStreamKey]) {
            $params[] = $eventId;
            $types[] = ParameterType::INTEGER;
            $params[] = $queryStreamKey;
            $types[] = ParameterType::BINARY;
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (event_id, query_stream_key) VALUES %s',
                $this->eventQueryStreamsTable(),
                $placeholders,
            ),
            $params,
            $types,
        );
    }

    /**
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

        if ([] === $modeByKey) {
            return;
        }

        $lockInstructions = [];
        foreach ($modeByKey as $queryStreamKey => $mode) {
            $lockId = $this->advisoryLockId($queryStreamKey);
            if (isset($lockInstructions[$lockId]) && 'exclusive' === $lockInstructions[$lockId]) {
                continue;
            }

            $lockInstructions[$lockId] = $mode;
        }

        ksort($lockInstructions, SORT_NUMERIC);

        $this->connection->executeQuery(
            sprintf('SELECT %s(?::bigint[], ?::boolean[])', $this->acquireAdvisoryLocksFunction()),
            [
                $this->postgresIntArrayLiteral(array_keys($lockInstructions)),
                $this->postgresBoolArrayLiteral(array_values($lockInstructions)),
            ],
        )->free();
    }

    private function eventsTable(): string
    {
        return $this->tablePrefix.'events';
    }

    private function eventQueryStreamsTable(): string
    {
        return $this->tablePrefix.'event_query_streams';
    }

    private function acquireAdvisoryLocksFunction(): string
    {
        return $this->tablePrefix.'acquire_advisory_locks';
    }

    private function advisoryLockId(string $queryStreamKey): int
    {
        return unpack('J', hex2bin(hash('xxh3', $queryStreamKey)))[1];
    }

    /**
     * @param list<string> $queryStreamKeys
     *
     * @return list<string>
     */
    private function storageKeysForQueryStreams(array $queryStreamKeys): array
    {
        sort($queryStreamKeys);

        return array_map(
            fn (string $queryStreamKey): string => $this->storageKeyForQueryStream($queryStreamKey),
            $queryStreamKeys,
        );
    }

    private function storageKeyForQueryStream(string $queryStreamKey): string
    {
        return hash('sha256', $queryStreamKey, true);
    }

    /**
     * @param list<int> $values
     */
    private function postgresIntArrayLiteral(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }

    /**
     * @param list<string> $values
     */
    private function postgresBoolArrayLiteral(array $values): string
    {
        $encoded = array_map(
            static fn (string $value): string => 'exclusive' === $value ? 'true' : 'false',
            $values,
        );

        return '{'.implode(',', $encoded).'}';
    }
}
