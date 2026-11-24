<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\LockStrategy;

use Ultra\Schema\PostgresFunctionDefinition;

interface SchemaLockStrategy extends LockStrategy
{
    /** @return list<PostgresFunctionDefinition> */
    public function functionDefinitions(string $tablePrefix): array;
}
