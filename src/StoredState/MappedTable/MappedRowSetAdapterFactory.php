<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedRowSetAdapterFactory
{
    /** @param list<string> $keyColumns */
    public function create(string $tableName, array $keyColumns = ['id']): MappedRowSetAdapter;

    /** @param list<string> $keyColumns */
    public function createScoped(string $tableName, array $keyColumns, MappedKey $scope): MappedRowSetAdapter;
}
