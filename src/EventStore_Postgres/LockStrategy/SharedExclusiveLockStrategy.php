<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\LockStrategy;

use Doctrine\DBAL\Connection;

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

    public function acquire(Connection $connection, string $tablePrefix, array $readKeys, array $writeKeys): void
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

        $connection->executeQuery(
            sprintf('SELECT %s(?::bigint[], ?::boolean[])', $this->acquireAdvisoryLocksFunction($tablePrefix)),
            [
                $this->postgresIntArrayLiteral(array_keys($lockInstructions)),
                $this->postgresBoolArrayLiteral(array_values($lockInstructions)),
            ],
        )->free();
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
