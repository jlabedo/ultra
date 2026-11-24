<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\ForeignKeyTiming;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

/** @internal */
final readonly class SchemaSnapshot
{
    private const int FORMAT = 1;

    public function __construct(
        public ?string $previous,
        public SchemaDefinition $schema,
    ) {}

    public function toJson(): string
    {
        return json_encode(
            [
                'format' => self::FORMAT,
                'previous' => $this->previous,
                'tables' => array_map(self::encodeTable(...), $this->schema->tables),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    public static function fromJson(string $json): self
    {
        $snapshot = self::record(json_decode($json, flags: JSON_THROW_ON_ERROR), 'snapshot');
        if (self::FORMAT !== ($snapshot['format'] ?? null)) {
            throw new \UnexpectedValueException('Unsupported schema snapshot format.');
        }

        $schema = new SchemaDefinition(array_map(self::decodeTable(...), self::items($snapshot, 'tables')));
        $schema->validateForeignKeys();

        return new self(self::nullableString($snapshot, 'previous'), $schema);
    }

    /** @return array<string, mixed> */
    private static function encodeTable(TableDefinition $table): array
    {
        return [
            'id' => $table->id,
            'name' => $table->name,
            'hasExplicitName' => $table->hasExplicitName,
            'columns' => array_map(static fn(ColumnDefinition $column): array => [
                'id' => $column->id,
                'name' => $column->name,
                'hasExplicitName' => $column->hasExplicitName,
                'type' => $column->type instanceof ColumnType ? ['portable' => $column->type->name] : $column->type,
                'nullable' => $column->nullable,
                'defaultExpression' => $column->defaultExpression,
                'constraints' => $column->constraints,
            ], $table->columns),
            'indexes' => array_map(static fn(IndexDefinition $index): array => [
                'id' => $index->id,
                'name' => $index->name,
                'hasExplicitName' => $index->hasExplicitName,
                'columns' => $index->columns,
                'unique' => $index->unique,
                'rawSql' => $index->rawSql,
            ], $table->indexes),
            'primaryKey' => $table->primaryKey,
            'foreignKeys' => (object) array_map(static fn(ForeignKeyDefinition $foreignKey): array => [
                'table' => $foreignKey->table,
                'columns' => (object) $foreignKey->columns,
                'timing' => $foreignKey->timing->name,
            ], $table->foreignKeys),
            'checks' => (object) $table->checks,
        ];
    }

    private static function decodeTable(mixed $value): TableDefinition
    {
        $table = self::record($value, 'table');
        $foreignKeys = [];
        foreach (self::record($table['foreignKeys'] ?? null, 'foreignKeys') as $name => $value) {
            $foreignKey = self::record($value, 'foreign key');
            $foreignKeys[(string) $name] = new ForeignKeyDefinition(
                table: self::string($foreignKey, 'table'),
                columns: self::stringMap($foreignKey, 'columns'),
                timing: self::enumCase(ForeignKeyTiming::cases(), self::string($foreignKey, 'timing')),
            );
        }

        return new TableDefinition(
            id: self::string($table, 'id'),
            name: self::string($table, 'name'),
            columns: array_map(self::decodeColumn(...), self::items($table, 'columns')),
            indexes: array_map(self::decodeIndex(...), self::items($table, 'indexes')),
            hasExplicitName: self::bool($table, 'hasExplicitName'),
            primaryKey: self::stringList($table, 'primaryKey'),
            foreignKeys: $foreignKeys,
            checks: self::stringMap($table, 'checks'),
        );
    }

    private static function decodeColumn(mixed $value): ColumnDefinition
    {
        $column = self::record($value, 'column');
        $type = $column['type'] ?? null;
        if (!is_string($type)) {
            $type = self::enumCase(ColumnType::cases(), self::string(self::record($type, 'type'), 'portable'));
        }

        return new ColumnDefinition(
            id: self::string($column, 'id'),
            name: self::string($column, 'name'),
            type: $type,
            nullable: self::bool($column, 'nullable'),
            defaultExpression: self::nullableString($column, 'defaultExpression'),
            constraints: self::stringList($column, 'constraints'),
            hasExplicitName: self::bool($column, 'hasExplicitName'),
        );
    }

    private static function decodeIndex(mixed $value): IndexDefinition
    {
        $index = self::record($value, 'index');

        return new IndexDefinition(
            id: self::string($index, 'id'),
            name: self::string($index, 'name'),
            columns: self::stringList($index, 'columns'),
            unique: self::bool($index, 'unique'),
            rawSql: self::nullableString($index, 'rawSql'),
            hasExplicitName: self::bool($index, 'hasExplicitName'),
        );
    }

    /**
     * @template Case of \UnitEnum
     * @param list<Case> $cases
     * @return Case
     */
    private static function enumCase(array $cases, string $name): \UnitEnum
    {
        foreach ($cases as $case) {
            if ($name === $case->name) {
                return $case;
            }
        }

        throw new \UnexpectedValueException(sprintf('Unknown schema snapshot enum case "%s".', $name));
    }

    /** @return array<array-key, mixed> */
    private static function record(mixed $value, string $field): array
    {
        if (!$value instanceof \stdClass) {
            throw self::invalidField($field);
        }

        return get_object_vars($value);
    }

    /**
     * @param array<array-key, mixed> $record
     * @return list<mixed>
     */
    private static function items(array $record, string $field): array
    {
        $value = $record[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalidField($field);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $record
     * @return list<string>
     */
    private static function stringList(array $record, string $field): array
    {
        return array_map(
            static fn(mixed $value): string => self::stringValue($value, $field),
            self::items($record, $field),
        );
    }

    /**
     * @param array<array-key, mixed> $record
     * @return array<string, string>
     */
    private static function stringMap(array $record, string $field): array
    {
        $result = [];
        foreach (self::record($record[$field] ?? null, $field) as $key => $value) {
            $result[(string) $key] = self::stringValue($value, $field);
        }

        return $result;
    }

    private static function stringValue(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw self::invalidField($field);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $record */
    private static function string(array $record, string $field): string
    {
        return self::nullableString($record, $field) ?? throw self::invalidField($field);
    }

    /** @param array<array-key, mixed> $record */
    private static function nullableString(array $record, string $field): ?string
    {
        if (!array_key_exists($field, $record)) {
            throw self::invalidField($field);
        }

        $value = $record[$field];
        if (null !== $value && !is_string($value)) {
            throw self::invalidField($field);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $record */
    private static function bool(array $record, string $field): bool
    {
        $value = $record[$field] ?? null;
        if (!is_bool($value)) {
            throw self::invalidField($field);
        }

        return $value;
    }

    private static function invalidField(string $field): \UnexpectedValueException
    {
        return new \UnexpectedValueException(sprintf('Invalid schema snapshot field "%s".', $field));
    }
}
