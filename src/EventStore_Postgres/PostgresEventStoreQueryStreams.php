<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ultra\EventStore\CanonicalQuery;
use Ultra\EventStore\CanonicalQueryKeyFactory;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\QueryVersion;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\LockStrategy\LockStrategy;
use Ultra\EventStore_Postgres\LockStrategy\SharedExclusiveLockStrategy;

/**
 * PostgreSQL event store implementation backed by a normalized domain-identifier index.
 *
 * Events are stored once in the `events` table, while `event_domain_ids` stores one row per event domain
 * identifier. `load()` is lock-free. `append()` acquires transaction-scoped advisory locks, replays each
 * expected query to compare its version, and only then inserts new events.
 */
final class PostgresEventStoreQueryStreams implements EventStore
{
    private CanonicalQueryKeyFactory $queryKeyFactory;

    public function __construct(
        private readonly Connection      $connection,
        private readonly string          $tablePrefix = 'event_store_',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly LockStrategy    $lockStrategy = new SharedExclusiveLockStrategy(),
    ) {
        $this->queryKeyFactory = new CanonicalQueryKeyFactory();
    }

    public function load(StreamQuery $query): LoadResult
    {
        $canonicalQuery = $this->queryKeyFactory->resolveQuery($query);
        if ($query->isEmpty()) {
            return new LoadResult([], QueryVersion::empty($query));
        }

        $events = [] === $canonicalQuery->branches ? [] : $this->fetchEvents($canonicalQuery);

        return new LoadResult($events, new QueryVersion(count($events), $query));
    }

    public function append(array $events, QueryVersion ...$expectedVersions): void
    {
        $readKeys = [];
        $expectedQueries = [];
        foreach ($expectedVersions as $expectedVersion) {
            $expectedCanonicalQuery = $this->queryKeyFactory->resolveQuery($expectedVersion->query);
            $expectedQueries[] = [$expectedVersion, $expectedCanonicalQuery];
            foreach ($expectedCanonicalQuery->allKeys() as $readKey) {
                $readKeys[$readKey] = true;
            }
        }

        $eventsToInsert = [];
        $writeKeys = [];
        foreach ($events as $event) {
            $eventKeys = $this->queryKeyFactory->keysForEvent($event->eventType, $event->domainIds);
            $eventsToInsert[] = $event;
            foreach ($eventKeys as $eventKey) {
                $writeKeys[$eventKey] = true;
            }
        }

        $ownsTransaction = ! $this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $this->lockStrategy->acquire($this->connection, $this->tablePrefix, array_keys($readKeys), array_keys($writeKeys));

            foreach ($expectedQueries as [$expectedVersion, $expectedCanonicalQuery]) {
                $currentVersion = new QueryVersion($this->fetchQueryVersion($expectedCanonicalQuery), $expectedVersion->query);
                $currentVersion->assertMatches($expectedVersion);
            }

            if ([] !== $eventsToInsert) {
                $eventIds = $this->insertEvents($eventsToInsert);

                $domainIdentifierRows = [];
                foreach ($eventsToInsert as $index => $event) {
                    $eventId = $eventIds[$index];
                    foreach ($event->domainIds as $name => $value) {
                        $domainIdentifierRows[] = [$eventId, $name, $value];
                    }
                }

                $this->insertEventDomainIdentifiers($domainIdentifierRows);
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
    private function fetchQueryVersion(CanonicalQuery $canonicalQuery): int
    {
        if ([] === $canonicalQuery->branches) {
            return 0;
        }

        [$whereClause, $params] = $this->canonicalQueryPredicate($canonicalQuery);

        return (int) $this->connection->executeQuery(
            sprintf(
                'SELECT COUNT(*)
                 FROM %s e
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
    private function fetchEvents(CanonicalQuery $canonicalQuery): array
    {
        [$whereClause, $params] = $this->canonicalQueryPredicate($canonicalQuery);

        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT e.event_id, e.event_type, e.payload, e.domain_ids, e.recorded_at
                 FROM %1$s e
                 WHERE %2$s
                 ORDER BY e.event_id ASC',
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

        return array_map(static fn(mixed $eventId): int => (int) $eventId, $eventIds);
    }

    /**
     * @param list<array{0: int, 1: string, 2: string}> $rows
     */
    private function insertEventDomainIdentifiers(array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?, ?)'));
        $params = [];
        $types = [];
        foreach ($rows as [$eventId, $name, $value]) {
            $params[] = $eventId;
            $types[] = ParameterType::INTEGER;
            $params[] = $name;
            $types[] = ParameterType::STRING;
            $params[] = $value;
            $types[] = ParameterType::STRING;
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (event_id, identifier_name, identifier_value) VALUES %s',
                $this->eventDomainIdsTable(),
                $placeholders,
            ),
            $params,
            $types,
        );
    }

    private function eventsTable(): string
    {
        return $this->tablePrefix . 'events';
    }

    private function eventDomainIdsTable(): string
    {
        return $this->tablePrefix . 'event_domain_ids';
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
            $identifierClauses = [];
            $domainIdentifiers = $branch->domainIdentifiers;
            $branchParams = $branch->eventTypes;
            ksort($domainIdentifiers);

            foreach ($domainIdentifiers as $name => $value) {
                $identifierClauses[] = sprintf(
                    'EXISTS (
                        SELECT 1
                        FROM %s d
                        WHERE d.event_id = e.event_id
                          AND d.identifier_name = ?
                          AND d.identifier_value = ?
                    )',
                    $this->eventDomainIdsTable(),
                );

                $branchParams[] = $name;
                $branchParams[] = $value;
            }

            $clauses[] = sprintf(
                '(e.event_type IN (%s) AND %s)',
                $eventTypePlaceholders,
                [] === $identifierClauses ? 'TRUE' : implode(' AND ', $identifierClauses),
            );

            array_push($params, ...$branchParams);
        }

        return [implode(' OR ', $clauses), $params];
    }

}
