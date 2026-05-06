<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedRowSetAdapterFactory
{
    public function create(string $tableName, string $idColumn = 'id'): MappedRowSetAdapter;

    /**
     * @param array<string, int|string> $scopeColumns
     */
    public function createScoped(string $tableName, string $idColumn, array $scopeColumns): MappedRowSetAdapter;
}
