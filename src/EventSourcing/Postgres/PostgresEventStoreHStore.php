<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\Sequence;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\LockStrategy\LockStrategy;
use Ultra\EventStore_Postgres\LockStrategy\SharedExclusiveLockStrategy;

/**
 * PostgreSQL implementation of the event store backed by stream queries.
 *
 * Events are stored once in the `events` table, with `domain_ids` persisted directly as `hstore`. Queries are
 * resolved against optional event type filters and indexed `domain_ids` containment. `load()` is lock-free.
 * `append()` acquires transaction-scoped advisory locks, validates each append condition checkpoint, and only
 * then inserts new events.
 */
final class PostgresEventStoreHStore implements EventStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tablePrefix = 'event_store_',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly LockStrategy $lockStrategy = new SharedExclusiveLockStrategy(),
    ) {}

    /**
     * Load and materialize the events matched by a query.
     */
    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Event-store batch size must be at least 1.');
        }

        if ($query->isEmpty()) {
            return new LoadResult([], $to ?? $from ?? Checkpoint::beginning(), $query);
        }

        return null === $to
            ? $this->fetchCapturedEvents($query, $from, $batchSize)
            : $this->fetchBoundedEvents($query, $from, $to, $batchSize);
    }

    /**
     * Append events and, when requested, verify append conditions under transaction-scoped advisory locks.
     */
    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        if ([] === $events) {
            return;
        }
        $readQueries = array_map(
            static fn (AppendCondition $appendCondition): StreamQuery => $appendCondition->query,
            $appendConditions,
        );

        $ownsTransaction = !$this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $this->lockStrategy->acquire($this->connection, $this->tablePrefix, $readQueries, $events);

            foreach ($appendConditions as $appendCondition) {
                if ([] !== $this->load($appendCondition->query, from: $appendCondition->checkpoint, batchSize: 1)->events) {
                    throw new ConcurrencyException($appendCondition->query->describe());
                }
            }

            $this->insertEvents($events);

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
     * @return list<Event>
     */
    private function mapEvents(array $rows): array
    {
        $events = [];
        foreach ($rows as $row) {
            $events[] = new Event(
                eventType: $row['event_type'],
                payload: json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
                domainIds: json_decode($row['domain_ids'], true, 512, JSON_THROW_ON_ERROR),
                recordedAt: new \DateTimeImmutable($row['recorded_at']),
                metadata: json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR),
            );
        }

        return $events;
    }

    private function fetchCapturedEvents(StreamQuery $query, ?Checkpoint $from, int $batchSize): LoadResult
    {
        [$domainPredicate, $domainParams, $domainTypes] = $this->queryPredicate($query);
        [$fromPredicate, $fromParams, $fromTypes] = $this->fromPredicate($from);
        $params = [...$fromParams, ...$domainParams];
        $types = [...$fromTypes, ...$domainTypes];

        $whereClause = $this->andPredicates($fromPredicate, $domainPredicate);
        $params[] = $batchSize + 1;
        $types[] = ParameterType::INTEGER;

        $rows = $this->connection->executeQuery(
            sprintf(
                'WITH snap AS (SELECT pg_current_snapshot() AS s)
                 SELECT e.sequence,
                        CASE WHEN e.xid >= pg_snapshot_xmin(snap.s) THEN 1 ELSE 0 END AS in_gap,
                        e.event_type,
                        e.payload,
                        e.metadata,
                        hstore_to_json(e.domain_ids) AS domain_ids,
                        e.recorded_at
                 FROM %s e, snap
                 WHERE %s
                 ORDER BY e.sequence ASC
                 LIMIT ?',
                $this->eventsTable(),
                $whereClause,
            ),
            $params,
            $types,
        )->fetchAllAssociative();

        $hasMore = count($rows) > $batchSize;
        if ($hasMore) {
            array_pop($rows);
        }

        return new LoadResult(
            $this->mapEvents($rows),
            $this->captureCheckpoint($from, $rows),
            $query,
            $hasMore,
        );
    }

    private function fetchBoundedEvents(StreamQuery $query, ?Checkpoint $from, Checkpoint $to, int $batchSize): LoadResult
    {
        [$domainPredicate, $domainParams, $domainTypes] = $this->queryPredicate($query);
        [$fromPredicate, $fromParams, $fromTypes] = $this->fromPredicate($from);
        [$toPredicate, $toParams, $toTypes] = $this->toPredicate($to);
        $params = [...$fromParams, ...$toParams, ...$domainParams];
        $types = [...$fromTypes, ...$toTypes, ...$domainTypes];

        $whereClause = $this->andPredicates($fromPredicate, $toPredicate, $domainPredicate);
        $params[] = $batchSize + 1;
        $types[] = ParameterType::INTEGER;

        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT e.sequence, e.event_type, e.payload, e.metadata, hstore_to_json(e.domain_ids) AS domain_ids, e.recorded_at
                 FROM %s e
                 WHERE %s
                 ORDER BY e.sequence ASC
                 LIMIT ?',
                $this->eventsTable(),
                $whereClause,
            ),
            $params,
            $types,
        )->fetchAllAssociative();

        if (count($rows) <= $batchSize) {
            return new LoadResult($this->mapEvents($rows), $to, $query);
        }

        $rows = array_slice($rows, 0, $batchSize);

        return new LoadResult(
            $this->mapEvents($rows),
            $this->captureCheckpoint($from, $rows, capturedInGap: false),
            $query,
            hasMore: true,
        );
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
            $placeholders[] = sprintf('(?, ?::jsonb, ?::jsonb, %s)', $domainIdsSql);
            $params[] = $event->eventType;
            $params[] = json_encode($event->payload, JSON_THROW_ON_ERROR);
            $params[] = json_encode($event->metadata, JSON_THROW_ON_ERROR);
            array_push($params, ...$domainIdsParams);
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %1$s (event_type, payload, metadata, domain_ids) VALUES %2$s',
                $this->eventsTable(),
                implode(', ', $placeholders),
            ),
            $params,
        );
    }

    private function eventsTable(): string
    {
        return $this->tablePrefix.'events';
    }

    /**
     * @return array{0: string, 1: list<mixed>, 2: list<mixed>}
     */
    private function queryPredicate(StreamQuery $query): array
    {
        $clauses = [];
        $params = [];
        $types = [];

        foreach ($query->branches as $branch) {
            [$domainIdsSql, $domainIdsParams] = $this->hstoreValueExpression($branch->domainIdentifiers);

            if ([] === $branch->eventTypes) {
                $clauses[] = sprintf('(e.domain_ids @> %s)', $domainIdsSql);
                array_push($params, ...$domainIdsParams);
                array_push($types, ...array_fill(0, count($domainIdsParams), ParameterType::STRING));
            } else {
                $eventTypePlaceholders = implode(', ', array_fill(0, count($branch->eventTypes), '?'));
                $clauses[] = sprintf(
                    '(e.event_type IN (%s) AND e.domain_ids @> %s)',
                    $eventTypePlaceholders,
                    $domainIdsSql,
                );

                array_push($params, ...$branch->eventTypes, ...$domainIdsParams);
                array_push($types, ...array_fill(0, count($branch->eventTypes) + count($domainIdsParams), ParameterType::STRING));
            }
        }

        return [implode(' OR ', $clauses), $params, $types];
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
     * @return array{0: string, 1: list<mixed>, 2: list<mixed>}
     */
    private function fromPredicate(?Checkpoint $from): array
    {
        if (null === $from) {
            return ['TRUE', [], []];
        }

        $predicate = ['e.sequence > ?'];
        $params = [$from->gapFloor->value];
        $types = [ParameterType::INTEGER];
        $gapSeqNos = $from->gapSeqNoValues();

        if ([] !== $gapSeqNos) {
            $predicate[] = 'e.sequence <> ALL(?)';
            $params[] = $this->sequenceArrayLiteral($gapSeqNos);
            $types[] = ParameterType::STRING;
        }

        return [implode(' AND ', $predicate), $params, $types];
    }

    /**
     * @return array{0: string, 1: list<mixed>, 2: list<mixed>}
     */
    private function toPredicate(Checkpoint $to): array
    {
        $params = [$to->gapFloor->value];
        $types = [ParameterType::INTEGER];

        if ($to->isSimplePosition()) {
            return ['e.sequence <= ?', $params, $types];
        }

        $params[] = $this->sequenceArrayLiteral($to->gapSeqNoValues());
        $types[] = ParameterType::STRING;

        return ['(e.sequence <= ? OR e.sequence = ANY(?))', $params, $types];
    }

    /**
     * @param non-empty-list<int> $sequences
     */
    private function sequenceArrayLiteral(array $sequences): string
    {
        return sprintf('{%s}', implode(',', $sequences));
    }

    private function andPredicates(string ...$predicates): string
    {
        return implode(
            ' AND ',
            array_map(
                static fn (string $predicate): string => sprintf('(%s)', $predicate),
                $predicates,
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function captureCheckpoint(?Checkpoint $from, array $rows, ?bool $capturedInGap = null): Checkpoint
    {
        $checkpoint = $from ?? Checkpoint::beginning();

        foreach ($rows as $row) {
            $checkpoint = $checkpoint->record(
                new Sequence((int) $row['sequence']),
                $capturedInGap ?? (1 === (int) $row['in_gap']),
            );
        }

        return $checkpoint;
    }
}
