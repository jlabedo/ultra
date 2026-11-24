<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\Schema\SchemaDefinition;

final readonly class PostgresProjectionSchema
{
    public function __construct(
        private string $tablePrefix = PostgresProjectionImplementation::DEFAULT_TABLE_PREFIX,
    ) {}

    public function definition(): SchemaDefinition
    {
        if (str_contains($this->tablePrefix, '.')) {
            throw new \InvalidArgumentException(
                'Projection migrations require an unqualified table prefix. Select the PostgreSQL schema through search_path.',
            );
        }

        return new SchemaDefinition([
            ...new PostgresProjectionSegmentSchema($this->tablePrefix)->definition()->tables,
            new PostgresDirtyKeyTrackerSchema($this->tablePrefix . 'dirty_keys')->tableDefinition(
                'projection.dirty_keys',
            ),
            new PostgresCoordinatorLeaseSchema($this->tablePrefix . 'coordinator_lease')->tableDefinition(
                'projection.coordinator_lease',
            ),
        ]);
    }
}
