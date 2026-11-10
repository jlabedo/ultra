<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\Projection\ProjectionPhase;
use Ultra\Projection\ProjectionState;
use Ultra\Projection\ProjectionStateRepository;

final readonly class PostgresProjectionStateRepository implements ProjectionStateRepository
{
    public function __construct(private Connection $connection, private string $tablePrefix = 'projection_') {}

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
            'SELECT projection_id, generation, phase, fingerprint FROM ' . $this->tablePrefix . 'states WHERE projection_id = ?',
            [$projectionId],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->connection->fetchAllAssociative(
            'SELECT projection_id, generation, phase, fingerprint FROM ' . $this->tablePrefix . 'states ORDER BY projection_id',
        ));
    }

    public function save(ProjectionState $state): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->tablePrefix . 'states (projection_id, generation, phase, fingerprint) VALUES (?, ?, ?, ?) '
            . 'ON CONFLICT (projection_id) DO UPDATE SET generation = EXCLUDED.generation, phase = EXCLUDED.phase, fingerprint = EXCLUDED.fingerprint',
            [$state->projectionId, $state->generation, $state->phase->value, $state->fingerprint],
        );
    }

    private function lock(string $projectionId, string $function): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Projection barriers require an active transaction.');
        }
        if (\Doctrine\DBAL\TransactionIsolationLevel::READ_COMMITTED !== $this->connection->getTransactionIsolation()) {
            throw new \LogicException('Managed projections require READ COMMITTED isolation.');
        }
        $this->connection->executeQuery(
            'SELECT ' . $function . '(hashtextextended(?, 0))',
            ['ultra.projection:' . $this->tablePrefix . ':' . $projectionId],
        )->free();
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
