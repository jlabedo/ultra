<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\IndexDefinition;
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

    public function test_it_generates_only_the_required_alter_column_clauses(): void
    {
        $current = new SchemaDefinition([
            new TableDefinition('table-users', 'users', [
                new ColumnDefinition('col-score', 'score', 'integer', nullable: false, defaultExpression: '0'),
            ]),
        ]);

        $target = new SchemaDefinition([
            new TableDefinition('table-users', 'users', [
                new ColumnDefinition('col-score', 'score', 'bigint', nullable: false, defaultExpression: '0'),
            ]),
        ]);

        $operations = (new SchemaReconciler())->reconcile($current, $target);
        $sql = (new PostgresSqlGenerator())->generate($operations);

        self::assertSame([
            'ALTER TABLE "users" ALTER COLUMN "score" TYPE bigint;',
        ], $sql);
    }

    public function test_it_can_drop_a_default_without_unrelated_alter_clauses(): void
    {
        $current = new SchemaDefinition([
            new TableDefinition('table-users', 'users', [
                new ColumnDefinition('col-score', 'score', 'integer', nullable: false, defaultExpression: '0'),
            ]),
        ]);

        $target = new SchemaDefinition([
            new TableDefinition('table-users', 'users', [
                new ColumnDefinition('col-score', 'score', 'integer', nullable: false, defaultExpression: null),
            ]),
        ]);

        $operations = (new SchemaReconciler())->reconcile($current, $target);
        $sql = (new PostgresSqlGenerator())->generate($operations);

        self::assertSame([
            'ALTER TABLE "users" ALTER COLUMN "score" DROP DEFAULT;',
        ], $sql);
    }

    public function test_it_generates_sql_for_index_rename_and_index_recreation(): void
    {
        $current = new SchemaDefinition([
            new TableDefinition(
                'table-users',
                'users',
                [new ColumnDefinition('col-email', 'email', 'text')],
                [new IndexDefinition('idx-email', 'users_email_idx', ['email'])],
            ),
        ]);

        $target = new SchemaDefinition([
            new TableDefinition(
                'table-users',
                'users',
                [new ColumnDefinition('col-email', 'email', 'text')],
                [new IndexDefinition('idx-email', 'app_users_email_idx', ['email'], unique: true)],
            ),
        ]);

        $operations = (new SchemaReconciler())->reconcile($current, $target);
        $sql = (new PostgresSqlGenerator())->generate($operations);

        self::assertSame([
            'ALTER INDEX "users_email_idx" RENAME TO "app_users_email_idx";',
            'DROP INDEX "app_users_email_idx";',
            'CREATE UNIQUE INDEX "app_users_email_idx" ON "users" ("email");',
        ], $sql);
    }

    public function test_it_supports_raw_sql_for_advanced_indexes(): void
    {
        $current = new SchemaDefinition([
            new TableDefinition('table-documents', 'documents_table', []),
        ]);

        $target = new SchemaDefinition([
            new TableDefinition(
                'table-documents',
                'documents_table',
                [],
                [
                    new IndexDefinition(
                        'idx-gin-locations',
                        'gin_idx',
                        [],
                        rawSql: 'CREATE INDEX gin_idx ON documents_table USING GIN (locations) WITH (fastupdate = off)',
                    ),
                ],
            ),
        ]);

        $operations = (new SchemaReconciler())->reconcile($current, $target);
        $sql = (new PostgresSqlGenerator())->generate($operations);

        self::assertSame([
            'CREATE INDEX gin_idx ON documents_table USING GIN (locations) WITH (fastupdate = off);',
        ], $sql);
    }
}
