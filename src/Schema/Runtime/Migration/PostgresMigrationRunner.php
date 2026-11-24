<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * @internal
 * @phpstan-type Migration array{id: string, snapshotHash: string, sql: ?string, sqlHash: ?string}
 */
final readonly class PostgresMigrationRunner
{
    private MigrationHistory $history;
    private string $journal;

    public function __construct(
        private Connection $connection,
        string $directory,
        string $metadataTable,
    ) {
        $this->history = new MigrationHistory($directory);
        $parts = explode('.', $metadataTable);
        if (1 === count($parts)) {
            array_unshift($parts, 'public');
        }
        if (
            2 !== count($parts)
            || !array_all(
                $parts,
                static fn(string $part): bool => 1 === preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/', $part),
            )
        ) {
            throw new \InvalidArgumentException(
                'The migration metadata table must be a lowercase PostgreSQL table name, optionally schema-qualified.',
            );
        }
        $this->journal = '"' . implode('"."', $parts) . '"';
    }

    /** @return list<array{id: string, status: string, executedAt: ?string}> */
    public function status(): array
    {
        $this->assertPostgres();
        $migrations = $this->history->migrations();
        $applied = $this->verifiedJournal($migrations);
        $status = [];
        foreach ($migrations as $index => $migration) {
            $status[] = [
                'id' => $migration['id'],
                'status' => match (true) {
                    isset($applied[$index]) => 'Applied',
                    null === $migration['sql'] => 'Baseline required',
                    default => 'Pending',
                },
                'executedAt' => $applied[$index] ?? null,
            ];
        }

        return $status;
    }

    /** @return list<string> */
    public function migrate(): array
    {
        return $this->locked(function (): array {
            $migrations = $this->history->migrations();
            $pending = array_slice($migrations, count($this->verifiedJournal($migrations)));
            if ([] !== $pending && null === $pending[0]['sql']) {
                throw new \LogicException(
                    'Record 000000-baseline with ultra:schema:baseline before applying migrations.',
                );
            }

            $applied = [];
            foreach ($pending as $migration) {
                try {
                    $this->apply($migration);
                } catch (\Throwable $exception) {
                    throw new \RuntimeException(
                        sprintf('Migration "%s" failed: %s', $migration['id'], $exception->getMessage()),
                        previous: $exception,
                    );
                }
                $applied[] = $migration['id'];
            }

            return $applied;
        });
    }

    public function baseline(): bool
    {
        return $this->locked(function (): bool {
            $migrations = $this->history->migrations();
            $baseline = $migrations[0] ?? null;
            if (null === $baseline || null !== $baseline['sql']) {
                throw new \LogicException('The migration history has no 000000-baseline entry.');
            }
            if ([] !== $this->verifiedJournal($migrations)) {
                return false;
            }

            $this->apply($baseline);

            return true;
        });
    }

    /**
     * @param list<Migration> $migrations
     * @return list<string> Applied timestamps in history order.
     */
    private function verifiedJournal(array $migrations): array
    {
        if (!$this->journalExists()) {
            return [];
        }

        $executed = [];
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT id, snapshot_sha256, sql_sha256, executed_at::text AS executed_at FROM %s ORDER BY id COLLATE "C"',
            $this->journal,
        ));
        foreach ($rows as $index => $row) {
            $migration = $migrations[$index] ?? null;
            if (null === $migration || $migration['id'] !== $row['id']) {
                throw new \UnexpectedValueException(sprintf(
                    'Applied migration "%s" does not match the versioned history at position %d (expected "%s").',
                    (string) $row['id'],
                    $index + 1,
                    $migration['id'] ?? '(none)',
                ));
            }
            if (
                $migration['snapshotHash'] !== $row['snapshot_sha256']
                || $migration['sqlHash'] !== $row['sql_sha256']
            ) {
                throw new \UnexpectedValueException(sprintf(
                    'Applied migration "%s" has been modified.',
                    $migration['id'],
                ));
            }
            $executed[] = (string) $row['executed_at'];
        }

        return $executed;
    }

    private function journalExists(): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 WHERE to_regclass(?) IS NOT NULL', [$this->journal]);
    }

    /** @param Migration $migration */
    private function apply(array $migration): void
    {
        $this->connection->transactional(function (Connection $connection) use ($migration): void {
            if (!$this->journalExists()) {
                $connection->executeStatement(sprintf('CREATE TABLE %s (
                        id text PRIMARY KEY,
                        snapshot_sha256 text NOT NULL,
                        sql_sha256 text,
                        executed_at timestamptz NOT NULL DEFAULT clock_timestamp()
                    )', $this->journal));
                $connection->executeStatement(sprintf("COMMENT ON TABLE %s IS 'managed-by:ultra'", $this->journal));
            }
            if (null !== $migration['sql']) {
                $connection->executeStatement(self::transactionalScript($migration['sql']));
            }
            $connection->executeStatement(
                sprintf('INSERT INTO %s (id, snapshot_sha256, sql_sha256) VALUES (?, ?, ?)', $this->journal),
                [$migration['id'], $migration['snapshotHash'], $migration['sqlHash']],
            );
        });
    }

    private static function transactionalScript(string $sql): string
    {
        // PostgreSQL rejects transaction control inside a PL/pgSQL EXECUTE.
        $tag = 'ultra_script';
        while (str_contains($sql, '$' . $tag . '$')) {
            $tag .= '_';
        }
        $script = '$' . $tag . '$';
        $tag = 'ultra_migration';
        while (str_contains($sql, '$' . $tag . '$')) {
            $tag .= '_';
        }
        $block = '$' . $tag . '$';

        return 'DO ' . $block . ' BEGIN EXECUTE ' . $script . $sql . $script . '; END; ' . $block;
    }

    /** @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function locked(\Closure $operation): mixed
    {
        $this->assertPostgres();
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Schema migrations require a connection outside a transaction.');
        }

        $key = 'ultra.schema.migrations:' . $this->journal;
        $this->connection->fetchOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);
        try {
            return $operation();
        } finally {
            $this->connection->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
        }
    }

    private function assertPostgres(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \InvalidArgumentException('Schema migrations require PostgreSQL.');
        }
    }
}
