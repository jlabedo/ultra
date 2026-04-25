<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;
use Ultra\Schema\TableDefinition;

/**
 * @internal
 */
#[CoversClass(PostgresSqlGenerator::class)]
final class PostgresSqlGeneratorTest extends TestCase
{
    public function test_it_generates_postgres_sql_for_rename_and_add_column_migration(): void
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
        $sql = (new PostgresSqlGenerator())->generate($operations);

        self::assertSame([
            'ALTER TABLE "users" RENAME TO "app_users";',
            'ALTER TABLE "app_users" RENAME COLUMN "full_name" TO "name";',
            'ALTER TABLE "app_users" ADD COLUMN "created_at" timestamp with time zone NOT NULL DEFAULT now();',
        ], $sql);
    }
}
