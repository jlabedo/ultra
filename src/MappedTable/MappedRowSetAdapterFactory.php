<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedRowSetAdapterFactory
{
    public function create(string $tableName, string $idColumn = 'id'): MappedRowSetAdapter;
}
