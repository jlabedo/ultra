<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\Operations\AddIndex;
use Ultra\Schema\Operations\AlterColumn;
use Ultra\Schema\Operations\SchemaOperation;
use Ultra\Schema\PostgresPolicyDefinition;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\RowLevelSecurity;
use Ultra\Schema\TableDefinition;

/** @internal */
#[CoversClass(PostgresSqlGenerator::class)]
final class MigrationInspectionTest extends TestCase
{
    #[DataProvider('invalidOperations')]
    public function test_invalid_operations_fail_before_returning_a_script(
        SchemaOperation $operation,
        string $message,
    ): void {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        new PostgresSqlGenerator()->generate([$operation]);
    }

    /** @return iterable<string, array{SchemaOperation, string}> */
    public static function invalidOperations(): iterable
    {
        yield 'empty alteration' => [new AlterColumn('users', 'score'), 'does not contain any effective change'];
        yield 'empty raw SQL' => [
            new AddIndex('users', new IndexDefinition('i', rawSql: " \n ")),
            'Raw SQL statement cannot be empty.',
        ];
        yield 'unknown operation' => [new class implements SchemaOperation {}, 'Unsupported schema operation:'];
    }

    #[DataProvider('tablesRequiringRlsMigration')]
    public function test_single_statement_bootstrap_rejects_tables_requiring_rls_migrations(TableDefinition $table): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Use a schema migration');
        new PostgresSqlGenerator()->createTableIfNotExists($table, 'documents');
    }

    /** @return iterable<string, array{TableDefinition}> */
    public static function tablesRequiringRlsMigration(): iterable
    {
        yield 'activation' => [new TableDefinition('documents', rowLevelSecurity: RowLevelSecurity::Enabled)];
        yield 'policies' => [new TableDefinition('documents', policies: ['owners' => new PostgresPolicyDefinition()])];
    }

    public function test_raw_sql_is_preserved_and_terminated_before_the_management_comment(): void
    {
        $raw = 'CREATE INDEX gin_idx ON documents USING GIN (locations) WITH (fastupdate = off)';
        $sql = new PostgresSqlGenerator()->generate([
            new AddIndex('documents', new IndexDefinition('i', 'gin_idx', rawSql: " \n" . $raw . "; \n")),
        ]);
        static::assertSame(
            [
                $raw . ';',
                'COMMENT ON INDEX "gin_idx" IS \'managed-by:ultra\';',
            ],
            $sql,
        );
    }
}
