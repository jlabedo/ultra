<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\ForeignKeyTiming;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\PostgresFunctionDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SqlType;
use Ultra\Schema\TableDefinition;

/** @internal */
final class SchemaDefinitionData
{
    /** @return array{tables: list<array<string, mixed>>, requiredExtensions: list<string>, functions: list<array<string, mixed>>} */
    public static function encode(SchemaDefinition $schema): array
    {
        return [
            'tables' => array_map(self::encodeTable(...), $schema->tables),
            'requiredExtensions' => $schema->requiredExtensions,
            'functions' => array_map(static fn(PostgresFunctionDefinition $function): array => [
                'id' => $function->id,
                'name' => $function->name,
                'arguments' => (object) $function->arguments,
                'returnType' => $function->returnType,
                'language' => $function->language,
                'body' => $function->body,
            ], $schema->functions),
        ];
    }

    public static function decode(mixed $data): SchemaDefinition
    {
        $record = self::record($data, 'schema') + ['requiredExtensions' => [], 'functions' => []];
        $schema = new SchemaDefinition(
            array_map(self::decodeTable(...), self::items($record, 'tables')),
            self::stringList($record, 'requiredExtensions'),
            array_map(self::decodeFunction(...), self::items($record, 'functions')),
        );
        $schema->validateForeignKeys();

        return $schema;
    }

    private static function decodeFunction(mixed $value): PostgresFunctionDefinition
    {
        $function = self::record($value, 'function');

        return new PostgresFunctionDefinition(
            id: self::string($function, 'id'),
            name: self::string($function, 'name'),
            arguments: self::stringMap($function, 'arguments'),
            returnType: self::string($function, 'returnType'),
            language: self::string($function, 'language'),
            body: self::string($function, 'body'),
        );
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
                'type' => $column->type->sql,
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
                'method' => $index->method,
                'predicate' => $index->predicate,
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
        return new ColumnDefinition(
            id: self::string($column, 'id'),
            name: self::string($column, 'name'),
            type: SqlType::fromSql(self::string($column, 'type')),
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
            method: self::nullableString($index + ['method' => null], 'method'),
            predicate: self::nullableString($index + ['predicate' => null], 'predicate'),
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
        return self::stringValue($record[$field] ?? null, $field);
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
