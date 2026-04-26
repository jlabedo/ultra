<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

/**
 * @internal
 */
#[CoversClass(SchemaDefinition::class)]
#[CoversClass(ColumnDefinition::class)]
#[CoversClass(IndexDefinition::class)]
#[CoversClass(TableDefinition::class)]
final class SchemaDefinitionValidationTest extends TestCase
{
    public function test_objects_without_names_default_to_their_ids(): void
    {
        $table = new TableDefinition('table-users', columns: [
            new ColumnDefinition('col-id', type: 'uuid'),
        ], indexes: [
            new IndexDefinition('idx-id', columns: ['col-id']),
        ]);

        self::assertSame('table-users', $table->name);
        self::assertSame('col-id', $table->columns[0]->name);
        self::assertSame('idx-id', $table->indexes[0]->name);
    }

    public function test_it_merges_table_fragments_with_names_and_columns(): void
    {
        $schema = SchemaDefinition::merge(
            new SchemaDefinition([
                new TableDefinition('table-users', columns: [
                    new ColumnDefinition('col-id', type: 'uuid'),
                ]),
            ]),
            new SchemaDefinition([
                new TableDefinition('table-users', 'users'),
            ]),
            new SchemaDefinition([
                new TableDefinition('table-users', columns: [
                    new ColumnDefinition('col-email', 'email', 'text'),
                ], indexes: [
                    new IndexDefinition('idx-email', 'users_email_idx', ['email']),
                ]),
            ]),
        );

        self::assertCount(1, $schema->tables);
        self::assertSame('users', $schema->tables[0]->name);
        self::assertSame(['col-id', 'col-email'], array_map(
            static fn (ColumnDefinition $column): string => $column->id,
            $schema->tables[0]->columns,
        ));
        self::assertSame(['col-id', 'email'], array_map(
            static fn (ColumnDefinition $column): string => $column->name,
            $schema->tables[0]->columns,
        ));
        self::assertSame('users_email_idx', $schema->tables[0]->indexes[0]->name);
    }

    public function test_it_rejects_conflicting_merged_table_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting table name for "table-users": "users" and "accounts".');

        SchemaDefinition::merge(
            new SchemaDefinition([
                new TableDefinition('table-users', 'users'),
            ]),
            new SchemaDefinition([
                new TableDefinition('table-users', 'accounts'),
            ]),
        );
    }

    public function test_it_rejects_conflicting_merged_column_definitions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting column definition for "col-id" in table "table-users".');

        SchemaDefinition::merge(
            new SchemaDefinition([
                new TableDefinition('table-users', columns: [
                    new ColumnDefinition('col-id', type: 'uuid'),
                ]),
            ]),
            new SchemaDefinition([
                new TableDefinition('table-users', columns: [
                    new ColumnDefinition('col-id', type: 'text'),
                ]),
            ]),
        );
    }

    public function test_it_rejects_conflicting_merged_column_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting column name for "col-id": "id" and "user_id".');

        SchemaDefinition::merge(
            new SchemaDefinition([
                new TableDefinition('table-users', columns: [
                    new ColumnDefinition('col-id', 'id', 'uuid'),
                ]),
            ]),
            new SchemaDefinition([
                new TableDefinition('table-users', columns: [
                    new ColumnDefinition('col-id', 'user_id', 'uuid'),
                ]),
            ]),
        );
    }

    public function test_it_rejects_duplicate_table_ids(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate table id "table-users".');

        new SchemaDefinition([
            new TableDefinition('table-users', 'users', [
                new ColumnDefinition('col-id', 'id', 'uuid'),
            ]),
            new TableDefinition('table-users', 'accounts', [
                new ColumnDefinition('col-id', 'id', 'uuid'),
            ]),
        ]);
    }

    public function test_it_rejects_duplicate_table_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate table name "users".');

        new SchemaDefinition([
            new TableDefinition('table-users', 'users', [
                new ColumnDefinition('col-id', 'id', 'uuid'),
            ]),
            new TableDefinition('table-accounts', 'users', [
                new ColumnDefinition('col-id', 'id', 'uuid'),
            ]),
        ]);
    }

    public function test_it_rejects_duplicate_column_ids_within_a_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate column id "col-id" in table "users".');

        new TableDefinition('table-users', 'users', [
            new ColumnDefinition('col-id', 'id', 'uuid'),
            new ColumnDefinition('col-id', 'user_id', 'uuid'),
        ]);
    }

    public function test_it_rejects_duplicate_column_names_within_a_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate column name "id" in table "users".');

        new TableDefinition('table-users', 'users', [
            new ColumnDefinition('col-id', 'id', 'uuid'),
            new ColumnDefinition('col-user-id', 'id', 'uuid'),
        ]);
    }

    public function test_it_rejects_duplicate_index_ids_within_a_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate index id "idx-email" in table "users".');

        new TableDefinition('table-users', 'users', indexes: [
            new IndexDefinition('idx-email', 'users_email_idx', ['email']),
            new IndexDefinition('idx-email', 'users_email_lookup_idx', ['email']),
        ]);
    }

    public function test_it_rejects_duplicate_index_names_within_a_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate index name "users_email_idx" in table "users".');

        new TableDefinition('table-users', 'users', indexes: [
            new IndexDefinition('idx-email', 'users_email_idx', ['email']),
            new IndexDefinition('idx-email-lookup', 'users_email_idx', ['email']),
        ]);
    }
}
