<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\LockStrategy;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;

/**
 * Former lock strategy: read keys use shared advisory locks, write keys use exclusive advisory locks.
 */
final readonly class SharedExclusiveLockStrategy implements LockStrategy
{
    public function name(): string
    {
        return 'shared-exclusive';
    }

    public function createStatements(string $tablePrefix): array
    {
        return [
            sprintf(
                <<<'SQL'
                    CREATE FUNCTION %1$sacquire_advisory_locks(lock_ids BIGINT[], exclusive_modes BOOLEAN[]) RETURNS void AS $$
                    DECLARE
                        position INTEGER;
                    BEGIN
                        IF COALESCE(array_length(lock_ids, 1), 0) <> COALESCE(array_length(exclusive_modes, 1), 0) THEN
                            RAISE EXCEPTION 'Lock id and mode array lengths must match';
                        END IF;

                        FOR position IN 1..COALESCE(array_length(lock_ids, 1), 0)
                        LOOP
                            IF exclusive_modes[position] THEN
                                PERFORM pg_advisory_xact_lock(lock_ids[position]);
                            ELSE
                                PERFORM pg_advisory_xact_lock_shared(lock_ids[position]);
                            END IF;
                        END LOOP;
                    END;
                    $$ LANGUAGE plpgsql
                    SQL,
                $tablePrefix,
            ),
        ];
    }

    public function dropStatements(string $tablePrefix): array
    {
        return [
            sprintf('DROP FUNCTION IF EXISTS %sacquire_advisory_locks(BIGINT[], BOOLEAN[])', $tablePrefix),
        ];
    }

    public function acquire(Connection $connection, string $tablePrefix, array $readQueries, array $writeEvents): void
    {
        [$readKeys, $writeKeys] = $this->lockKeys($readQueries, $writeEvents);
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

        $connection->executeQuery(
            sprintf('SELECT %s(?::bigint[], ?::boolean[])', $this->acquireAdvisoryLocksFunction($tablePrefix)),
            [
                $this->postgresIntArrayLiteral(array_keys($lockInstructions)),
                $this->postgresBoolArrayLiteral(array_values($lockInstructions)),
            ],
        )->free();
    }

    /**
     * @param list<StreamQuery> $readQueries
     * @param list<Event>       $writeEvents
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function lockKeys(array $readQueries, array $writeEvents): array
    {
        $lockKeys = new StreamQueryLockKeys();
        $readKeys = [];
        foreach ($readQueries as $query) {
            foreach ($lockKeys->readKeys($query) as $readKey) {
                $readKeys[$readKey] = true;
            }
        }

        $writeKeys = [];
        foreach ($writeEvents as $event) {
            foreach ($lockKeys->writeKeysForEvent($event->eventType, $event->domainIds) as $writeKey) {
                $writeKeys[$writeKey] = true;
            }
        }

        return [array_keys($readKeys), array_keys($writeKeys)];
    }

    private function acquireAdvisoryLocksFunction(string $tablePrefix): string
    {
        return $tablePrefix . 'acquire_advisory_locks';
    }

    private function advisoryLockId(string $queryStreamKey): int
    {
        return unpack('J', hex2bin(hash('xxh3', $queryStreamKey)))[1];
    }

    /**
     * @param list<int> $values
     */
    private function postgresIntArrayLiteral(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }

    /**
     * @param list<string> $values
     */
    private function postgresBoolArrayLiteral(array $values): string
    {
        $encoded = array_map(
            static fn(string $value): string => 'exclusive' === $value ? 'true' : 'false',
            $values,
        );

        return '{' . implode(',', $encoded) . '}';
    }
}
