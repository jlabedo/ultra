<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\Schema\ColumnType;
use Ultra\Schema\TableDefinition;

/** Select a Schema table for MappedTable. The table ID is also the Symfony service ID. */
final readonly class SchemaMappedRowSet
{
    /**
     * @param ?list<string> $columns Exposed column IDs. Defaults to all columns, and always includes the primary key.
     * @param array<string, string> $representations PHP representations by column ID.
     * @param array<string, PostgresMappedColumnType> $postgresColumnTypes Codecs by column ID.
     */
    public function __construct(
        public string $table,
        public ?string $owner = null,
        public ?array $columns = null,
        public array $representations = [],
        public array $postgresColumnTypes = [],
    ) {
        if ('' === $table || is_numeric($table)) {
            throw new \InvalidArgumentException('A mapped schema table needs a non-empty, non-numeric ID.');
        }
    }

    /**
     * @internal
     * @param array<string, TableDefinition> $tables
     * @return array{MappedRowSetMapping, array<string, PostgresMappedColumnType>}
     */
    public function compile(array $tables, bool $postgres): array
    {
        $table = $tables[$this->table] ?? throw new \LogicException(sprintf(
            'Unknown schema table "%s".',
            $this->table,
        ));
        if ([] === $table->primaryKey) {
            throw new \LogicException(sprintf('Mapped schema table "%s" requires a primary key.', $this->table));
        }

        $declared = $table->columnsById();
        $exposed = null === $this->columns
            ? array_keys($declared)
            : array_unique([...$table->primaryKey, ...$this->columns]);
        $mapping = [];
        $codecs = [];
        foreach ($exposed as $id) {
            $column = $declared[$id] ?? throw new \LogicException(sprintf(
                'Unknown column "%s" on schema table "%s".',
                $id,
                $this->table,
            ));
            $representation = $this->representations[$id] ?? match ($column->type) {
                ColumnType::Text => 'string',
                ColumnType::Int32 => 'int',
                default => throw new \LogicException(sprintf(
                    'Select a PHP representation for column "%s" on schema table "%s".',
                    $id,
                    $this->table,
                )),
            };
            $mapping[$column->name] = new MappedColumn($representation, $column->nullable);
            if ($postgres && isset($this->postgresColumnTypes[$id])) {
                $codecs[$column->name] = $this->postgresColumnTypes[$id];
            }
        }

        $options = $postgres ? [...$this->representations, ...$this->postgresColumnTypes] : $this->representations;
        foreach (array_diff(array_keys($options), $exposed) as $id) {
            throw new \LogicException(sprintf(
                'Column "%s" is not exposed by mapped schema table "%s".',
                $id,
                $this->table,
            ));
        }

        $keys = array_map(static fn(string $id): string => $declared[$id]->name, $table->primaryKey);

        return [
            new MappedRowSetMapping($table->name, $mapping, $keys, owner: $this->compileOwner($table, $tables)),
            $codecs,
        ];
    }

    /** @param array<string, TableDefinition> $tables */
    private function compileOwner(TableDefinition $table, array $tables): ?MappedRowSetOwner
    {
        if (null === $this->owner) {
            return null;
        }
        $foreignKey = $table->foreignKeys[$this->owner] ?? throw new \LogicException(sprintf(
            'Unknown owner foreign key "%s" on schema table "%s".',
            $this->owner,
            $this->table,
        ));
        return new MappedRowSetOwner(
            $foreignKey->table,
            $foreignKey->physicalColumns($table, $tables[$foreignKey->table]),
        );
    }
}
