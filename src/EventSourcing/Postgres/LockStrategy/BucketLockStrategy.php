<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\LockStrategy;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;

/**
 * Two-bucket strategy: operations in the same bucket can run together, opposite buckets serialize.
 *
 * Read keys acquire bucket A. Write keys acquire bucket B. A guarded append that reads and writes the
 * same key acquires both buckets for that key, which preserves optimistic concurrency validation while
 * still allowing unguarded writes for the same key to run concurrently.
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
                CREATE FUNCTION {$tablePrefix}_lock_key(ns text, bucket char)
                RETURNS integer
                LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
                AS $$
                    SELECT hashtext('pg_bucket_lock:' || ns || ':' || bucket);
                $$;
                SQL,
            <<<SQL
                CREATE FUNCTION {$tablePrefix}_normalize_bucket(bucket text)
                RETURNS char
                LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE
                AS $$
                BEGIN
                    IF upper(bucket) NOT IN ('A', 'B') THEN
                        RAISE EXCEPTION 'bucket must be ''A'' or ''B'', got ''%''', bucket;
                    END IF;
                    RETURN upper(bucket)::char;
                END;
                $$;
                SQL,
            <<<SQL
                CREATE FUNCTION {$tablePrefix}_other_bucket(bucket char)
                RETURNS char
                LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
                AS $$
                    SELECT CASE bucket WHEN 'A' THEN 'B'::char ELSE 'A'::char END;
                $$;
                SQL,
            <<<SQL
                CREATE FUNCTION {$tablePrefix}acquire(ns text, bucket text)
                RETURNS void
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    b         char := {$tablePrefix}_normalize_bucket(bucket);
                    other     char := {$tablePrefix}_other_bucket(b);
                    my_key    integer := {$tablePrefix}_lock_key(ns, b);
                    other_key integer := {$tablePrefix}_lock_key(ns, other);
                    acquired  boolean;
                    retries   integer := 0;
                    max_retries integer := 1000;
                BEGIN
                    LOOP
                        PERFORM pg_advisory_lock(other_key);

                        acquired := pg_try_advisory_lock_shared(my_key);

                        PERFORM pg_advisory_unlock(other_key);

                        IF acquired THEN
                            RETURN;
                        END IF;

                        retries := retries + 1;
                        IF retries >= max_retries THEN
                            RAISE EXCEPTION 'pg_bucket_lock: acquire(%, %) failed after % retries',
                                ns, b, max_retries;
                        END IF;

                        PERFORM pg_sleep(0.001 + random() * 0.049);
                    END LOOP;
                END;
                $$;
                SQL,
            <<<SQL
                CREATE FUNCTION {$tablePrefix}try_acquire(ns text, bucket text)
                RETURNS boolean
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    b         char := {$tablePrefix}_normalize_bucket(bucket);
                    other     char := {$tablePrefix}_other_bucket(b);
                    my_key    integer := {$tablePrefix}_lock_key(ns, b);
                    other_key integer := {$tablePrefix}_lock_key(ns, other);
                    acquired  boolean;
                BEGIN
                    IF NOT pg_try_advisory_lock(other_key) THEN
                        RETURN false;
                    END IF;

                    acquired := pg_try_advisory_lock_shared(my_key);

                    PERFORM pg_advisory_unlock(other_key);

                    RETURN acquired;
                END;
                $$;
                SQL,
            <<<SQL
                CREATE FUNCTION {$tablePrefix}release(ns text, bucket text)
                RETURNS boolean
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    b      char := {$tablePrefix}_normalize_bucket(bucket);
                    my_key integer := {$tablePrefix}_lock_key(ns, b);
                BEGIN
                    RETURN pg_advisory_unlock_shared(my_key);
                END;
                $$;
                SQL,
            <<<SQL
                CREATE FUNCTION {$tablePrefix}acquire_xact(ns text, bucket text)
                RETURNS void
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    b         char := {$tablePrefix}_normalize_bucket(bucket);
                    other     char := {$tablePrefix}_other_bucket(b);
                    my_key    integer := {$tablePrefix}_lock_key(ns, b);
                    other_key integer := {$tablePrefix}_lock_key(ns, other);
                    acquired  boolean;
                    retries   integer := 0;
                    max_retries integer := 1000;
                BEGIN
                    LOOP
                        PERFORM pg_advisory_lock(other_key);

                        acquired := pg_try_advisory_xact_lock_shared(my_key);

                        PERFORM pg_advisory_unlock(other_key);

                        IF acquired THEN
                            RETURN;
                        END IF;

                        retries := retries + 1;
                        IF retries >= max_retries THEN
                            RAISE EXCEPTION 'pg_bucket_lock: acquire_xact(%, %) failed after % retries',
                                ns, b, max_retries;
                        END IF;

                        PERFORM pg_sleep(0.001 + random() * 0.049);
                    END LOOP;
                END;
                $$;
                SQL,
            <<<SQL
                CREATE FUNCTION {$tablePrefix}try_acquire_xact(ns text, bucket text)
                RETURNS boolean
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    b         char := {$tablePrefix}_normalize_bucket(bucket);
                    other     char := {$tablePrefix}_other_bucket(b);
                    my_key    integer := {$tablePrefix}_lock_key(ns, b);
                    other_key integer := {$tablePrefix}_lock_key(ns, other);
                    acquired  boolean;
                BEGIN
                    IF NOT pg_try_advisory_lock(other_key) THEN
                        RETURN false;
                    END IF;

                    acquired := pg_try_advisory_xact_lock_shared(my_key);

                    PERFORM pg_advisory_unlock(other_key);

                    RETURN acquired;
                END;
                $$;
                SQL,
            <<<SQL
                CREATE FUNCTION {$tablePrefix}acquire_multi_xact(
                    namespaces text[],
                    buckets    text[]
                )
                RETURNS void
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    r record;
                BEGIN
                    IF array_length(namespaces, 1) IS DISTINCT FROM array_length(buckets, 1) THEN
                        RAISE EXCEPTION 'pg_bucket_lock: namespaces and buckets arrays must have the same length';
                    END IF;

                    FOR r IN
                        SELECT DISTINCT ns, bk
                        FROM unnest(namespaces, buckets) AS t(ns, bk)
                        ORDER BY ns, bk
                    LOOP
                        PERFORM {$tablePrefix}acquire_xact(r.ns, r.bk);
                    END LOOP;
                END;
                $$;
                SQL,
        ];
    }

    public function dropStatements(string $tablePrefix): array
    {
        return [
            sprintf('DROP FUNCTION IF EXISTS %sacquire_multi_xact(text[], text[])', $tablePrefix),
            sprintf('DROP FUNCTION IF EXISTS %stry_acquire_xact(text, text)', $tablePrefix),
            sprintf('DROP FUNCTION IF EXISTS %sacquire_xact(text, text)', $tablePrefix),
            sprintf('DROP FUNCTION IF EXISTS %srelease(text, text)', $tablePrefix),
            sprintf('DROP FUNCTION IF EXISTS %stry_acquire(text, text)', $tablePrefix),
            sprintf('DROP FUNCTION IF EXISTS %sacquire(text, text)', $tablePrefix),
            sprintf('DROP FUNCTION IF EXISTS %s_other_bucket(char)', $tablePrefix),
            sprintf('DROP FUNCTION IF EXISTS %s_normalize_bucket(text)', $tablePrefix),
            sprintf('DROP FUNCTION IF EXISTS %s_lock_key(text, char)', $tablePrefix),
        ];
    }

    public function acquire(Connection $connection, string $tablePrefix, array $readQueries, array $writeEvents): void
    {
        [$readKeys, $writeKeys] = $this->lockKeys($readQueries, $writeEvents);
        $lockInstructions = [];
        foreach ($readKeys as $readKey) {
            $lockInstructions[$readKey . "\0A"] = [$readKey, 'A'];
        }
        foreach ($writeKeys as $writeKey) {
            $lockInstructions[$writeKey . "\0B"] = [$writeKey, 'B'];
        }

        if ([] === $lockInstructions) {
            return;
        }

        ksort($lockInstructions, SORT_STRING);

        $namespaces = [];
        $buckets = [];
        foreach ($lockInstructions as [$namespace, $bucket]) {
            $namespaces[] = $namespace;
            $buckets[] = $bucket;
        }

        $connection
            ->executeQuery(sprintf('SELECT %s(?::text[], ?::text[])', $this->acquireMultiXactFunction($tablePrefix)), [
                $this->postgresStringArrayLiteral($namespaces),
                $this->postgresStringArrayLiteral($buckets),
            ])
            ->free();
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

    private function acquireMultiXactFunction(string $tablePrefix): string
    {
        return $tablePrefix . 'acquire_multi_xact';
    }

    /**
     * @param list<string> $values
     */
    private function postgresStringArrayLiteral(array $values): string
    {
        $encoded = array_map(
            static fn(string $value): string => (
                '"'
                . strtr($value, [
                    '\\' => '\\\\',
                    '"' => '\"',
                ])
                . '"'
            ),
            $values,
        );

        return '{' . implode(',', $encoded) . '}';
    }
}
