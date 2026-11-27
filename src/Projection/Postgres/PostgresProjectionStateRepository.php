<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use Ultra\Projection\ProjectionPhase;
use Ultra\Projection\ProjectionState;
use Ultra\Projection\ProjectionStateRepository;

final readonly class PostgresProjectionStateRepository implements ProjectionStateRepository
{
    public function __construct(
        private Connection $connection,
        private string $tablePrefix = 'projection_',
    ) {}

    public function lockForAppend(array $projectionIds): void
    {
        $this->requireBarrierTransaction();
        $keys = [$this->barrierKey('registry')];
        foreach ($projectionIds as $projectionId) {
            $keys[] = $this->barrierKey('projection:' . $projectionId);
        }

        $this->connection
            ->executeQuery(
                <<<'SQL'
                    SELECT pg_advisory_xact_lock_shared(hashtextextended(lock_key, 0))
                    FROM jsonb_array_elements_text(CAST(? AS jsonb)) WITH ORDINALITY AS locks(lock_key, position)
                    ORDER BY position
                    SQL,
                [json_encode($keys, JSON_THROW_ON_ERROR)],
            )
            ->free();
    }

    public function lockRegistrationShared(): void
    {
        $this->lock('registry', 'pg_advisory_xact_lock_shared');
    }

    public function lockRegistrationExclusive(): void
    {
        $this->lock('registry', 'pg_advisory_xact_lock');
    }

    public function lockShared(string $projectionId): void
    {
        $this->lock('projection:' . $projectionId, 'pg_advisory_xact_lock_shared');
    }

    public function lockExclusive(string $projectionId): void
    {
        $this->lock('projection:' . $projectionId, 'pg_advisory_xact_lock');
    }

    public function find(string $projectionId): ?ProjectionState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT projection_id, generation, phase, fingerprint FROM '
            . $this->tablePrefix
            . 'states WHERE projection_id = ?',
            [$projectionId],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    public function findMany(array $projectionIds): array
    {
        if ([] === $projectionIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT projection_id, generation, phase, fingerprint FROM '
            . $this->tablePrefix
            . 'states WHERE projection_id IN (?)',
            [$projectionIds],
            [ArrayParameterType::STRING],
        );
        $states = [];
        foreach ($rows as $row) {
            $state = $this->hydrate($row);
            $states[$state->projectionId] = $state;
        }

        return $states;
    }

    public function all(): array
    {
        return array_map(
            $this->hydrate(...),
            $this->connection->fetchAllAssociative(
                'SELECT projection_id, generation, phase, fingerprint FROM '
                . $this->tablePrefix
                . 'states ORDER BY projection_id',
            ),
        );
    }

    public function save(ProjectionState $state): void
    {
        $this->connection->executeStatement(
            'INSERT INTO '
            . $this->tablePrefix
            . 'states (projection_id, generation, phase, fingerprint) VALUES (?, ?, ?, ?) '
            . 'ON CONFLICT (projection_id) DO UPDATE SET generation = EXCLUDED.generation, phase = EXCLUDED.phase, fingerprint = EXCLUDED.fingerprint',
            [$state->projectionId, $state->generation, $state->phase->value, $state->fingerprint],
        );
    }

    private function lock(string $name, string $function): void
    {
        $this->requireBarrierTransaction();
        $this->connection
            ->executeQuery('SELECT ' . $function . '(hashtextextended(?, 0))', [$this->barrierKey($name)])
            ->free();
    }

    private function barrierKey(string $name): string
    {
        return 'ultra.projection:' . $this->tablePrefix . ':' . $name;
    }

    private function requireBarrierTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Projection barriers require an active transaction.');
        }
        if (TransactionIsolationLevel::READ_COMMITTED !== $this->connection->getTransactionIsolation()) {
            throw new \LogicException('Managed projections require READ COMMITTED isolation.');
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ProjectionState
    {
        return new ProjectionState(
            (string) $row['projection_id'],
            (int) $row['generation'],
            ProjectionPhase::from((string) $row['phase']),
            (string) $row['fingerprint'],
        );
    }
}
