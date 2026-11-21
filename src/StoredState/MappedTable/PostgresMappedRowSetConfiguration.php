<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface PostgresMappedRowSetConfiguration
{
    /** @return array<string, PostgresMappedColumnType> */
    public function postgresColumnTypes(): array;
}
