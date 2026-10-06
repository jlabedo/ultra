<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Tracking;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Coordination\Tracking\PendingKey;

final readonly class PostgresDirtyKeyTracker implements DirtyKeyTracker
{
    public function __construct(
        private Connection $connection,
        private string $table = 'coordination_dirty_keys',
    ) {}

    public function bump(string $channel, string $key): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %1$s (channel, key, counter) VALUES (?, ?, 1)
                 ON CONFLICT (channel, key)
                 DO UPDATE SET counter = %1$s.counter + 1, updated_at = statement_timestamp()',
                $this->table,
            ),
            [$channel, $key],
            [ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function pending(int $limit = 256): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT channel, key, counter FROM %s
                 WHERE counter > 0
                 ORDER BY counter DESC, channel ASC, key ASC
                 LIMIT ?',
                $this->table,
            ),
            [$limit],
            [ParameterType::INTEGER],
        )->fetchAllAssociative();

        return $this->hydrate($rows);
    }

    public function pendingForChannel(string $channel, int $limit = 256): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT channel, key, counter FROM %s
                 WHERE channel = ? AND counter > 0
                 ORDER BY counter DESC, key ASC
                 LIMIT ?',
                $this->table,
            ),
            [$channel, $limit],
            [ParameterType::STRING, ParameterType::INTEGER],
        )->fetchAllAssociative();

        return $this->hydrate($rows);
    }

    public function drainSeen(string $channel, array $seen): int
    {
        $seen = array_filter($seen, static fn (int $counter): bool => $counter > 0);
        if ([] === $seen) {
            return 0;
        }

        $values = [];
        $params = [];
        $types = [];
        foreach ($seen as $key => $counter) {
            $values[] = '(CAST(? AS TEXT), CAST(? AS BIGINT))';
            $params[] = (string) $key;
            $params[] = $counter;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::INTEGER;
        }

        $params[] = $channel;
        $types[] = ParameterType::STRING;

        return (int) $this->connection->executeStatement(
            sprintf(
                'UPDATE %1$s AS dirty
                 SET counter = GREATEST(dirty.counter - seen.seen_counter, 0),
                     updated_at = statement_timestamp()
                 FROM (VALUES %2$s) AS seen(key, seen_counter)
                 WHERE dirty.channel = ? AND dirty.key = seen.key AND dirty.counter > 0',
                $this->table,
                implode(', ', $values),
            ),
            $params,
            $types,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<PendingKey>
     */
    private function hydrate(array $rows): array
    {
        return array_map(
            static fn (array $row): PendingKey => new PendingKey(
                (string) $row['channel'],
                (string) $row['key'],
                (int) $row['counter'],
            ),
            $rows,
        );
    }
}
