<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\StreamQueryLockKeys;
use Ultra\EventStore_Postgres\LockStrategy\LockStrategy;
use Ultra\EventStore_Postgres\LockStrategy\SharedExclusiveLockStrategy;

/**
 * PostgreSQL implementation of the event store backed by typed stream queries.
 *
 * Events are stored once in the `events` table, with `domain_ids` persisted directly as `hstore`. Queries are
 * resolved against the typed event type and indexed `domain_ids` containment, while singleton/pair keys remain
 * the units used by optimistic-concurrency locks. `load()` is lock-free. `append()` acquires
 * transaction-scoped advisory locks, replays the expected query to compare its version, and only
 * then inserts new events.
 */
final class PostgresEventStoreHStore implements EventStore
{
    private StreamQueryLockKeys $queryLockKeys;

    public function __construct(
        private readonly Connection      $connection,
        private readonly string          $tablePrefix = 'event_store_',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly LockStrategy    $lockStrategy = new SharedExclusiveLockStrategy(),
    ) {
        $this->queryLockKeys = new StreamQueryLockKeys();
    }

    /**
     * Load and materialize the events matched by a typed query.
     */
    public function load(StreamQuery $query): LoadResult
    {
        if ($query->isEmpty()) {
            return new LoadResult([], QueryVersion::empty($query));
        }

        $this->queryLockKeys->assertQuerySupported($query);
        $events = $this->fetchEvents($query);

        return new LoadResult($events, new QueryVersion(count($events), $query));
    }

    /**
     * Append events and, when requested, verify the expected queries under transaction-scoped advisory locks.
     */
    public function append(array $events, QueryVersion ...$expectedVersions): void
    {
        $ownsTransaction = ! $this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $expectedQueries = [];
            $readKeys = [];
            foreach ($expectedVersions as $expectedVersion) {
                $expectedQueries[] = [$expectedVersion, $expectedVersion->query];
                foreach ($this->queryLockKeys->readKeys($expectedVersion->query) as $readKey) {
                    $readKeys[$readKey] = true;
                }
            }

            $eventsToInsert = [];
            $writeKeys = [];
            foreach ($events as $event) {
                $eventsToInsert[] = $event;
                $eventKeys = $this->queryLockKeys->writeKeysForEvent($event->eventType, $event->domainIds);
                foreach ($eventKeys as $eventKey) {
                    $writeKeys[$eventKey] = true;
                }
            }

            $this->lockStrategy->acquire($this->connection, $this->tablePrefix, array_keys($readKeys), array_keys($writeKeys));

            foreach ($expectedQueries as [$expectedVersion, $expectedQuery]) {
                $currentVersion = new QueryVersion($this->fetchQueryVersion($expectedQuery), $expectedVersion->query);
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
     * Return the visible version for the validated query.
     */
    private function fetchQueryVersion(StreamQuery $query): int
    {
        if ($query->isEmpty()) {
            return 0;
        }

        [$whereClause, $params] = $this->queryPredicate($query);

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
     * @return list<Event>
     */
    private function fetchEvents(StreamQuery $query): array
    {
        [$whereClause, $params] = $this->queryPredicate($query);

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

    private function eventsTable(): string
    {
        return $this->tablePrefix . 'events';
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function queryPredicate(StreamQuery $query): array
    {
        $clauses = [];
        $params = [];

        foreach ($query->branches as $branch) {
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

}
