<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\CanonicalQuery;
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
 * Events are stored once in the `events` table, with `domain_ids` persisted directly as `hstore`. Queries are
 * resolved against the typed event type and indexed `domain_ids` containment, while canonical singleton/pair
 * keys remain the units used by optimistic-concurrency locks. `load()` is lock-free. `append()` acquires
 * transaction-scoped advisory locks, replays the expected query to compare its version, and only
 * then inserts new events.
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
        if ($query->isEmpty()) {
            return new LoadResult([], QueryVersion::empty($query));
        }

        $canonicalQuery = $this->queryKeyFactory->resolveQuery($query);
        $events = [] === $canonicalQuery->branches ? [] : $this->fetchEvents($canonicalQuery);

        return new LoadResult($events, new QueryVersion(count($events), $query));
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
            $expectedCanonicalQuery = null;
            if (null !== $expectedVersion) {
                $expectedCanonicalQuery = $this->queryKeyFactory->resolveQuery($expectedVersion->query);
                $readKeys = $expectedCanonicalQuery->allKeys();
            }

            $eventsToInsert = [];
            $writeKeys = [];
            foreach ($events as $event) {
                $eventsToInsert[] = $event;
                $eventKeys = $this->queryKeyFactory->keysForEvent($event->eventType, $event->domainIds);
                foreach ($eventKeys as $eventKey) {
                    $writeKeys[$eventKey] = true;
                }
            }

            $this->acquireAdvisoryLocks($readKeys, array_keys($writeKeys));

            if (null !== $expectedVersion) {
                $currentVersion = new QueryVersion($this->fetchQueryVersion($expectedCanonicalQuery), $expectedVersion->query);
                $currentVersion->assertMatches($expectedVersion);
            }

            if ([] !== $eventsToInsert) {
                $this->insertEvents($eventsToInsert);
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
     * Return the visible version for the canonical query.
     */
    private function fetchQueryVersion(?CanonicalQuery $canonicalQuery): int
    {
        if (null === $canonicalQuery || [] === $canonicalQuery->branches) {
            return 0;
        }

        [$whereClause, $params] = $this->canonicalQueryPredicate($canonicalQuery);

        return (int) $this->connection->executeQuery(
            sprintf(
                'SELECT COUNT(*)
                 FROM %s
                 WHERE %s',
                $this->eventsTable(),
                $whereClause,
            ),
            $params,
        )->fetchOne();
    }

    /**
     * @return list<StoredEvent>
     */
    private function fetchEvents(CanonicalQuery $canonicalQuery): array
    {
        [$whereClause, $params] = $this->canonicalQueryPredicate($canonicalQuery);

        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT event_id, event_type, payload, hstore_to_json(domain_ids) AS domain_ids, recorded_at
                 FROM %s
                 WHERE %s
                 ORDER BY event_id ASC',
                $this->eventsTable(),
                $whereClause,
            ),
            $params,
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

    /**
     * @param list<AppendableEvent> $events
     */
    private function insertEvents(array $events): void
    {
        if ([] === $events) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach ($events as $event) {
            [$domainIdsSql, $domainIdsParams] = $this->hstoreValueExpression($event->domainIds);
            $placeholders[] = sprintf('(?, ?::jsonb, %s)', $domainIdsSql);
            $params[] = $event->eventType;
            $params[] = json_encode($event->payload, JSON_THROW_ON_ERROR);
            array_push($params, ...$domainIdsParams);
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %1$s (event_type, payload, domain_ids) VALUES %2$s',
                $this->eventsTable(),
                implode(', ', $placeholders),
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

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function canonicalQueryPredicate(CanonicalQuery $canonicalQuery): array
    {
        $clauses = [];
        $params = [];

        foreach ($canonicalQuery->branches as $branch) {
            $eventTypePlaceholders = implode(', ', array_fill(0, count($branch->eventTypes), '?'));
            [$domainIdsSql, $domainIdsParams] = $this->hstoreValueExpression($branch->domainIdentifiers);

            $clauses[] = sprintf(
                '(event_type IN (%s) AND domain_ids @> %s)',
                $eventTypePlaceholders,
                $domainIdsSql,
            );

            array_push($params, ...$branch->eventTypes, ...$domainIdsParams);
        }

        return [implode(' OR ', $clauses), $params];
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
     * @param array<string, string> $values
     *
     * @return array{0: string, 1: list<string>}
     */
    private function hstoreValueExpression(array $values): array
    {
        if ([] === $values) {
            return ["''::hstore", []];
        }

        ksort($values);

        $params = [];
        foreach ($values as $key => $value) {
            $params[] = $key;
            $params[] = $value;
        }

        return [
            sprintf('hstore(ARRAY[%s]::text[])', implode(', ', array_fill(0, count($params), '?'))),
            $params,
        ];
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
