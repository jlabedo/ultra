<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface PostgresMappedRowSetConfiguration
{
    /**
     * Read at container compilation when PostgreSQL is the selected adapter.
     *
     * @return array<string, PostgresMappedColumnType> Codecs by physical column name.
     */
    public function postgresColumnTypes(): array;
}
