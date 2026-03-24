<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

interface PostgresEventStoreSchemaDefinition
{
    /**
     * @return list<string>
     */
    public function createStatements(): array;

    /**
     * @return list<string>
     */
    public function dropStatements(): array;
}
