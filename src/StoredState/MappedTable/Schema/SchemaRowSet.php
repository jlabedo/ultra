<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Schema;

use Ultra\MappedTable\Codec\ArrayCodec;
use Ultra\MappedTable\Codec\BooleanCodec;
use Ultra\MappedTable\Codec\IntegerCodec;
use Ultra\MappedTable\Codec\JsonbCodec;
use Ultra\MappedTable\Codec\TemporalCodec;
use Ultra\MappedTable\Codec\TextCodec;
use Ultra\MappedTable\Codec\UuidCodec;
use Ultra\MappedTable\ColumnCodec;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SqlType;

/** Derives an explicit row-set definition from a Schema table. */
final class SchemaRowSet
{
    /**
     * @param ?list<string> $columns
     * @param array<string, ColumnCodec|string> $codecs Overrides by physical column name.
     * @param list<string> $ownerColumns
     */
    public static function fromTable(
        SchemaDefinition $schema,
        string $tableId,
        ?array $columns = null,
        ?string $owner = null,
        array $ownerColumns = [],
        array $codecs = [],
    ): MappedRowSetDefinition {
        $schema->validateForeignKeys();
        $tables = $schema->tablesById();
        if ('' === $tableId || is_numeric($tableId)) {
            throw new \InvalidArgumentException('A mapped schema table needs a non-empty, non-numeric ID.');
        }
        $table = $tables[$tableId] ?? throw new \LogicException(sprintf('Unknown schema table "%s".', $tableId));
        if ([] === $table->primaryKey) {
            throw new \LogicException(sprintf('Mapped schema table "%s" requires a primary key.', $tableId));
        }

        $declared = $table->columnsById();
        $exposed = null === $columns ? array_keys($declared) : array_unique([...$table->primaryKey, ...$columns]);
        $mapping = [];
        $nullable = [];
        foreach ($exposed as $id) {
            $column = $declared[$id] ?? throw new \LogicException(sprintf(
                'Unknown column "%s" on schema table "%s".',
                $id,
                $tableId,
            ));
            $mapping[] = $column->name;
            $codec = $codecs[$column->name] ?? self::defaultCodec($column->type);
            if (null !== $codec) {
                $codecs[$column->name] = $codec;
            }
            if ($column->nullable) {
                $nullable[] = $column->name;
            }
        }

        $keys = array_map(static fn(string $id): string => $declared[$id]->name, $table->primaryKey);
        $scope = array_map(
            static fn(string $id): string => (
                $declared[$id] ?? throw new \LogicException(sprintf(
                    'Unknown owner column "%s" on schema table "%s".',
                    $id,
                    $tableId,
                ))
            )->name,
            $ownerColumns,
        );

        $dependency = null;
        if (null !== $owner) {
            $foreignKey = $table->foreignKeys[$owner] ?? throw new \LogicException(sprintf(
                'Unknown owner foreign key "%s" on schema table "%s".',
                $owner,
                $tableId,
            ));
            $dependency = new MappedRowSetOwner(
                $foreignKey->table,
                $foreignKey->physicalColumns($table, $tables[$foreignKey->table]),
            );
        }

        return new MappedRowSetDefinition($table->name, $mapping, $keys, $codecs, $scope, $dependency, $nullable);
    }

    /** Codecs for the optional Schema integration. Unrecognized types use the raw driver value. */
    public static function defaultCodec(SqlType $type): ?ColumnCodec
    {
        return match ($type->family) {
            ColumnType::Text => new TextCodec(),
            ColumnType::Varchar => new TextCodec($type->length, stringableKeys: false),
            ColumnType::Int32 => new IntegerCodec(int32: true),
            ColumnType::Int64 => new IntegerCodec(),
            ColumnType::Boolean => new BooleanCodec(),
            ColumnType::Uuid => new UuidCodec(),
            ColumnType::Date => new TemporalCodec(TemporalCodec::DATE),
            ColumnType::Timestamp => new TemporalCodec(TemporalCodec::LOCAL_TIMESTAMP),
            ColumnType::TimestampWithTimeZone => new TemporalCodec(),
            ColumnType::TextArray => new ArrayCodec(),
            ColumnType::UuidArray => new ArrayCodec(new UuidCodec()),
            ColumnType::IntArray => new ArrayCodec(new IntegerCodec(int32: true)),
            ColumnType::Jsonb => new JsonbCodec(),
            null => null,
        };
    }
}
