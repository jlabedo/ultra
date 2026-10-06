<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\BucketHasher;
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
 * PostgreSQL event store implementation backed by a normalized domain-identifier index.
 *
 * Events are stored once in the `events` table, while `event_domain_ids` stores one row per event domain
 * identifier. `load()` is lock-free. `append()` acquires transaction-scoped advisory locks, validates each
 * append condition checkpoint, and only then inserts new events.
 */
final class PostgresEventStoreQueryStreams implements EventStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tablePrefix = 'event_store_',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly LockStrategy $lockStrategy = new SharedExclusiveLockStrategy(),
    ) {}

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

            $sequences = $this->insertEvents($events);

            $domainIdentifierRows = [];
            foreach ($events as $index => $event) {
                $sequence = $sequences[$index];
                foreach ($event->domainIds as $name => $value) {
                    $domainIdentifierRows[] = [$sequence, $name, $value];
                }
            }

            $this->insertEventDomainIdentifiers($domainIdentifierRows);

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
                        e.domain_ids,
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
                'SELECT e.sequence, e.event_type, e.payload, e.metadata, e.domain_ids, e.recorded_at
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
     *
     * @return list<int>
     */
    private function insertEvents(array $events): array
    {
        if ([] === $events) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($events), '(?, ?::jsonb, ?::jsonb, ?::jsonb)'));
        $params = [];
        $types = [];
        foreach ($events as $event) {
            $params[] = $event->eventType;
            $types[] = ParameterType::STRING;
            $params[] = json_encode($event->payload, JSON_THROW_ON_ERROR);
            $types[] = ParameterType::STRING;
            $params[] = json_encode($event->metadata, JSON_THROW_ON_ERROR);
            $types[] = ParameterType::STRING;
            $params[] = json_encode($event->domainIds, JSON_THROW_ON_ERROR);
            $types[] = ParameterType::STRING;
        }

        $sequences = $this->connection->executeQuery(
            sprintf(
                'INSERT INTO %1$s (event_type, payload, metadata, domain_ids) VALUES %2$s RETURNING sequence',
                $this->eventsTable(),
                $placeholders,
            ),
            $params,
            $types,
        )->fetchFirstColumn();

        return array_map(static fn (mixed $sequence): int => (int) $sequence, $sequences);
    }

    /**
     * @param list<array{0: int, 1: string, 2: string}> $rows
     */
    private function insertEventDomainIdentifiers(array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?)'));
        $params = [];
        $types = [];
        foreach ($rows as [$sequence, $name, $value]) {
            $params[] = $sequence;
            $types[] = ParameterType::INTEGER;
            $params[] = $name;
            $types[] = ParameterType::STRING;
            $params[] = $value;
            $types[] = ParameterType::STRING;
            $params[] = $this->computeBucket($value);
            $types[] = ParameterType::INTEGER;
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (sequence, identifier_name, identifier_value, bucket) VALUES %s',
                $this->eventDomainIdsTable(),
                $placeholders,
            ),
            $params,
            $types,
        );
    }

    /**
     * Maps an identifier value to a bucket [0-255] using the first byte of its MD5 hash.
     * MD5 is used for stability across PHP and PostgreSQL versions.
     */
    private function computeBucket(string $value): int
    {
        return BucketHasher::bucketFor($value);
    }

    private function eventsTable(): string
    {
        return $this->tablePrefix.'events';
    }

    private function eventDomainIdsTable(): string
    {
        return $this->tablePrefix.'event_domain_ids';
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
            $identifierClauses = [];
            $branchParams = [];
            $branchTypes = [];

            // Bucket-range predicate (segment-scoped queries)
            if (null !== $branch->bucketRange) {
                $identifierClauses[] = sprintf(
                    'EXISTS (
                        SELECT 1
                        FROM %s d
                        WHERE d.sequence = e.sequence
                          AND d.identifier_name = ?
                          AND d.bucket BETWEEN ? AND ?
                    )',
                    $this->eventDomainIdsTable(),
                );
                $branchParams[] = $branch->bucketRange['dimension'];
                $branchTypes[] = ParameterType::STRING;
                $branchParams[] = $branch->bucketRange['from'];
                $branchTypes[] = ParameterType::INTEGER;
                $branchParams[] = $branch->bucketRange['to'];
                $branchTypes[] = ParameterType::INTEGER;
            }

            // Exact domain identifier predicates
            $domainIdentifiers = $branch->domainIdentifiers;
            ksort($domainIdentifiers);
            foreach ($domainIdentifiers as $name => $value) {
                $identifierClauses[] = sprintf(
                    'EXISTS (
                        SELECT 1
                        FROM %s d
                        WHERE d.sequence = e.sequence
                          AND d.identifier_name = ?
                          AND d.identifier_value = ?
                    )',
                    $this->eventDomainIdsTable(),
                );
                $branchParams[] = $name;
                $branchTypes[] = ParameterType::STRING;
                $branchParams[] = $value;
                $branchTypes[] = ParameterType::STRING;
            }

            $identifierPredicate = [] === $identifierClauses ? 'TRUE' : implode(' AND ', $identifierClauses);

            if ([] === $branch->eventTypes) {
                $clauses[] = sprintf('(%s)', $identifierPredicate);
                array_push($params, ...$branchParams);
                array_push($types, ...$branchTypes);
            } else {
                $eventTypePlaceholders = implode(', ', array_fill(0, count($branch->eventTypes), '?'));
                $clauses[] = sprintf(
                    '(e.event_type IN (%s) AND %s)',
                    $eventTypePlaceholders,
                    $identifierPredicate,
                );
                array_push($params, ...$branch->eventTypes, ...$branchParams);
                array_push($types, ...array_fill(0, count($branch->eventTypes), ParameterType::STRING), ...$branchTypes);
            }
        }

        return [implode(' OR ', $clauses), $params, $types];
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
