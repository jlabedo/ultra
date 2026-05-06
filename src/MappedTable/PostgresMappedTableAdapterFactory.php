<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;

final readonly class PostgresMappedTableAdapterFactory implements MappedTableAdapterFactory
{
    /**
     * @param array<string, array<string, PostgresMappedColumnType>> $columnTypesByTable
     */
    public function __construct(
        private Connection $connection,
        private array $columnTypesByTable = [],
    ) {}

    public function create(string $tableName, string $idColumn = 'id'): MappedTableAdapter
    {
        return new PostgresMappedTableAdapter(
            $this->connection,
            $tableName,
            $idColumn,
            $this->columnTypesByTable[$tableName] ?? [],
        );
    }
}
