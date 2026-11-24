<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres;

use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Refresh\PostgresRefreshPublicationSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\Schema\SchemaDefinition;

final readonly class PostgresCoordinationSchema
{
    public function __construct(
        private ?string $dirtyKeys = null,
        private ?string $leases = null,
        private ?string $refreshRevisions = null,
    ) {}

    public function definition(): SchemaDefinition
    {
        foreach ([$this->dirtyKeys, $this->leases, $this->refreshRevisions] as $table) {
            if (null !== $table && str_contains($table, '.')) {
                throw new \InvalidArgumentException(
                    'Coordination migrations require unqualified table names. Select the PostgreSQL schema through search_path.',
                );
            }
        }

        $tables = [];
        if (null !== $this->dirtyKeys) {
            $tables[] = new PostgresDirtyKeyTrackerSchema($this->dirtyKeys)->tableDefinition('coordination.dirty_keys');
        }
        if (null !== $this->leases) {
            $tables[] = new PostgresCoordinatorLeaseSchema($this->leases)->tableDefinition('coordination.leases');
        }
        if (null !== $this->refreshRevisions) {
            $tables[] = new PostgresRefreshPublicationSchema($this->refreshRevisions)->tableDefinition(
                'coordination.refresh_revisions',
            );
        }

        return new SchemaDefinition($tables);
    }
}
