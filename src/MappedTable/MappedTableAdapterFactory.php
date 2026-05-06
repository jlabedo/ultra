<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedTableAdapterFactory
{
    public function create(string $tableName, string $idColumn = 'id'): MappedTableAdapter;
}
