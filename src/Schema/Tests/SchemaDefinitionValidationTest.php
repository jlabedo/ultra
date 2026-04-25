<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

/**
 * @internal
 */
#[CoversClass(SchemaDefinition::class)]
#[CoversClass(TableDefinition::class)]
final class SchemaDefinitionValidationTest extends TestCase
{
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
}
