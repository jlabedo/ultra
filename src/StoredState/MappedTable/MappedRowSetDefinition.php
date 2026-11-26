<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\Sql\ColumnType;
use Ultra\Sql\SqlType;

/** Physical columns and key used by a row buffer, independent of its storage and coordinator. */
final readonly class MappedRowSetDefinition
{
    /**
     * @param array<string, ColumnType|SqlType|string> $columns Physical column name to SQL type.
     * @param non-empty-list<string> $primaryKey
     * @param list<string> $nullable
     * @param list<string> $ownerColumns
     */
    public function __construct(
        public string $table,
        public array $columns,
        public array $primaryKey,
        public array $nullable = [],
        public array $ownerColumns = [],
        public ?MappedRowSetOwner $owner = null,
    ) {}

    /**
     * @internal
     * @param array<string, ColumnCodec|class-string<ColumnCodec>> $codecs
     */
    public function compile(array $codecs): MappedRowSetMapping
    {
        if ([] !== array_diff($this->nullable, array_keys($this->columns))) {
            throw new \InvalidArgumentException(sprintf('Unknown nullable column on mapped table "%s".', $this->table));
        }
        $columns = [];
        foreach ($this->columns as $name => $type) {
            $sql = SqlType::resolve($type);
            $codec = $codecs[$sql->sql] ?? ColumnCodecs::builtin($sql);
            if (null === $codec) {
                throw new \LogicException(sprintf(
                    'Unsupported SQL type "%s" for column "%s" on mapped table "%s".',
                    $sql->sql,
                    $name,
                    $this->table,
                ));
            }
            $columns[$name] = new MappedColumn($sql, in_array($name, $this->nullable, true), $codec);
        }

        return new MappedRowSetMapping($this->table, $columns, $this->primaryKey, $this->ownerColumns, $this->owner);
    }
}
