<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;
use Ultra\Schema\TableDefinition;
use Ultra\Schema\Operations\AddColumn;
use Ultra\Schema\Operations\RenameColumn;
use Ultra\Schema\Operations\RenameTable;

/**
 * @internal
 */
#[CoversClass(SchemaReconciler::class)]
final class SchemaReconcilerTest extends TestCase
{
    public function test_reconciler_detects_table_and_column_renames_by_id_and_adds_new_columns(): void
    {
        $current = new SchemaDefinition([
            new TableDefinition('table-users', 'users', [
                new ColumnDefinition('col-id', 'id', 'uuid'),
                new ColumnDefinition('col-name', 'full_name', 'text'),
            ]),
        ]);

        $target = new SchemaDefinition([
            new TableDefinition('table-users', 'app_users', [
                new ColumnDefinition('col-id', 'id', 'uuid'),
                new ColumnDefinition('col-name', 'name', 'text'),
                new ColumnDefinition('col-created-at', 'created_at', 'timestamp with time zone', defaultExpression: 'now()'),
            ]),
        ]);

        $operations = (new SchemaReconciler())->reconcile($current, $target);

        self::assertCount(3, $operations);
        self::assertInstanceOf(RenameTable::class, $operations[0]);
        self::assertInstanceOf(RenameColumn::class, $operations[1]);
        self::assertInstanceOf(AddColumn::class, $operations[2]);

        self::assertSame('users', $operations[0]->fromName);
        self::assertSame('app_users', $operations[0]->toName);
        self::assertSame('app_users', $operations[1]->tableName);
        self::assertSame('full_name', $operations[1]->fromName);
        self::assertSame('name', $operations[1]->toName);
    }
}
