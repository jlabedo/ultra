<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\LockStrategy;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;

/**
 * Same-side operations share a bucket; opposing sides exclude each other.
 *
 * Each namespace maps to two adjacent advisory keys: even for reads, odd for writes.
 * A read/write operation takes both keys exclusively, while a one-sided operation
 * briefly locks the opposite key before taking its own shared transaction lock.
 */
final readonly class BucketLockStrategy implements LockStrategy
{
    public function name(): string
    {
        return 'bucket';
    }

    public function createStatements(string $tablePrefix): array
    {
        return [
            <<<SQL
                CREATE FUNCTION {$tablePrefix}acquire_multi_xact(lock_ids bigint[], modes smallint[])
                RETURNS void
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    position integer;
                    read_key bigint;
                    my_key bigint;
                    other_key bigint;
                    acquired boolean;
                    retries integer;
                BEGIN
                    IF cardinality(lock_ids) IS DISTINCT FROM cardinality(modes) THEN
                        RAISE EXCEPTION 'Lock id and mode array lengths must match';
                    END IF;

                    -- The caller supplies deduplicated, numerically sorted namespace keys.
                    FOR position IN 1..COALESCE(cardinality(lock_ids), 0)
                    LOOP
                        read_key := lock_ids[position];
                        IF modes[position] = 3 THEN
                            -- Exclude both existing readers and existing writers.
                            PERFORM pg_advisory_xact_lock(read_key);
                            PERFORM pg_advisory_xact_lock(read_key + 1);
                            CONTINUE;
                        ELSIF modes[position] = 1 THEN
                            my_key := read_key;
                            other_key := read_key + 1;
                        ELSIF modes[position] = 2 THEN
                            my_key := read_key + 1;
                            other_key := read_key;
                        ELSE
                            RAISE EXCEPTION 'Invalid bucket mode: %', modes[position];
                        END IF;

                        retries := 0;
                        LOOP
                            PERFORM pg_advisory_lock(other_key);
                            acquired := pg_try_advisory_xact_lock_shared(my_key);
                            PERFORM pg_advisory_unlock(other_key);

                            IF acquired THEN
                                EXIT;
                            END IF;

                            retries := retries + 1;
                            IF retries >= 1000 THEN
                                RAISE EXCEPTION 'Bucket lock % (mode %) failed after % retries',
                                    read_key, modes[position], retries;
                            END IF;

                            PERFORM pg_sleep(0.001 + random() * 0.049);
                        END LOOP;
                    END LOOP;
                END;
                $$;
                SQL,
        ];
    }

    public function dropStatements(string $tablePrefix): array
    {
        return [sprintf('DROP FUNCTION IF EXISTS %sacquire_multi_xact(bigint[], smallint[])', $tablePrefix)];
    }

    /**
     * @param list<StreamQuery> $readQueries
     * @param list<Event> $writeEvents
     */
    public function acquire(Connection $connection, string $tablePrefix, array $readQueries, array $writeEvents): void
    {
        $keys = new StreamQueryLockKeys();
        $modeByKey = [];
        foreach ($readQueries as $query) {
            foreach ($keys->readKeys($query) as $key) {
                $modeByKey[$key] = 1;
            }
        }
        foreach ($writeEvents as $event) {
            foreach ($keys->writeKeysForEvent($event->eventType, $event->domainIds) as $key) {
                $modeByKey[$key] = ($modeByKey[$key] ?? 0) | 2;
            }
        }

        if ([] === $modeByKey) {
            return;
        }

        $instructions = [];
        foreach ($modeByKey as $key => $mode) {
            // Reserve the low bit for the side. A hash collision merges modes conservatively.
            $id = unpack('J', hash('xxh3', 'pg_bucket_lock:' . $key, true))[1] & ~1;
            $instructions[$id] = ($instructions[$id] ?? 0) | $mode;
        }
        ksort($instructions, SORT_REGULAR);

        $connection->executeQuery(sprintf('SELECT %sacquire_multi_xact(?::bigint[], ?::smallint[])', $tablePrefix), [
            '{' . implode(',', array_keys($instructions)) . '}',
            '{' . implode(',', array_values($instructions)) . '}',
        ])->free();
    }
}
