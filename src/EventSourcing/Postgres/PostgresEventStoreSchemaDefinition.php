<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Ultra\Schema\SchemaDefinition;

interface PostgresEventStoreSchemaDefinition
{
    public function definition(): SchemaDefinition;

    /**
     * @return list<string>
     */
    public function createStatements(): array;

    /**
     * @return list<string>
     */
    public function dropStatements(): array;
}
