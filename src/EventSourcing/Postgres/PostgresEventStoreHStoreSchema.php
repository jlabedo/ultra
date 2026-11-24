<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Ultra\EventStore_Postgres\LockStrategy\LockStrategy;
use Ultra\EventStore_Postgres\LockStrategy\SharedExclusiveLockStrategy;
use Ultra\Schema\SchemaDefinition;

/**
 * Generates the PostgreSQL DDL required by `PostgresEventStore`.
 */
final readonly class PostgresEventStoreHStoreSchema implements PostgresEventStoreSchemaDefinition
{
    public function __construct(
        private string $tablePrefix = PostgresEventStoreSchema::DEFAULT_TABLE_PREFIX,
        private LockStrategy $lockStrategy = new SharedExclusiveLockStrategy(),
    ) {}

    public function definition(): SchemaDefinition
    {
        return new PostgresEventStoreSchema($this->tablePrefix, $this->lockStrategy, hstore: true)->definition();
    }

    /** @return list<string> */
    public function createStatements(): array
    {
        return new PostgresEventStoreSchema($this->tablePrefix, $this->lockStrategy, hstore: true)->createStatements();
    }

    /**
     * Statements needed to tear down the schema created by `createStatements()`.
     *
     * @return list<string>
     */
    public function dropStatements(): array
    {
        return [
            ...$this->lockStrategy->dropStatements($this->tablePrefix),
            sprintf('DROP TABLE IF EXISTS %sevents', $this->tablePrefix),
        ];
    }
}
