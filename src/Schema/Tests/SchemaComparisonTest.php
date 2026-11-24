<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\Operations\AddIndex;
use Ultra\Schema\Operations\DropIndex;
use Ultra\Schema\Operations\RenameIndex;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;
use Ultra\Schema\TableDefinition;

/** @internal */
#[CoversClass(SchemaReconciler::class)]
#[CoversClass(ColumnDefinition::class)]
#[CoversClass(IndexDefinition::class)]
final class SchemaComparisonTest extends TestCase
{
    /** @param list<\Ultra\Schema\Operations\SchemaOperation> $expected */
    #[DataProvider('indexChanges')]
    public function test_index_changes_preserve_identity_and_replace_only_changed_definitions(
        IndexDefinition $old,
        IndexDefinition $new,
        array $expected,
    ): void {
        $current = new SchemaDefinition([new TableDefinition('t', 'users', indexes: [$old])]);
        $target = new SchemaDefinition([new TableDefinition('t', 'users', indexes: [$new])]);
        static::assertEquals($expected, new SchemaReconciler()->reconcile($current, $target));
    }

    /** @return iterable<string, array{IndexDefinition, IndexDefinition, list<\Ultra\Schema\Operations\SchemaOperation>}> */
    public static function indexChanges(): iterable
    {
        $old = new IndexDefinition('i', 'lookup', ['email']);
        $renamed = new IndexDefinition('i', 'email_lookup', ['email']);
        $unique = new IndexDefinition('i', 'email_lookup', ['email'], unique: true);
        $columns = new IndexDefinition('i', 'lookup', ['email', 'id']);
        $raw = new IndexDefinition('i', 'lookup', rawSql: 'CREATE INDEX lookup ON users USING GIN(email)');
        yield 'unchanged' => [$old, $old, []];
        yield 'rename' => [$old, $renamed, [new RenameIndex('users', 'lookup', 'email_lookup')]];
        yield 'unique and rename' => [
            $old,
            $unique,
            [
                new DropIndex('users', 'lookup'),
                new AddIndex('users', $unique),
            ],
        ];
        yield 'columns' => [$old, $columns, [new DropIndex('users', 'lookup'), new AddIndex('users', $columns)]];
        $changedRaw = new IndexDefinition('i', 'lookup', rawSql: $raw->rawSql . ' WITH (fastupdate = off)');
        yield 'raw SQL' => [$raw, $changedRaw, [new DropIndex('users', 'lookup'), new AddIndex('users', $changedRaw)]];
    }

    public function test_changed_column_constraints_are_rejected_even_after_renaming(): void
    {
        $current = new SchemaDefinition([new TableDefinition('t', 'users', [new ColumnDefinition(
            'c',
            'email',
            'text',
        )])]);
        $target = new SchemaDefinition([new TableDefinition('t', 'accounts', [new ColumnDefinition(
            'c',
            'address',
            'text',
            constraints: ['UNIQUE'],
        )])]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot reconcile changed constraints for column "accounts"."address".');
        new SchemaReconciler()->reconcile($current, $target);
    }

    public function test_changing_an_existing_primary_key_is_rejected_before_generating_sql(): void
    {
        $columns = [
            new ColumnDefinition('site', type: ColumnType::Text),
            new ColumnDefinition('number', type: ColumnType::Int32),
        ];
        $current = new SchemaDefinition([new TableDefinition('workshops', columns: $columns, primaryKey: ['number'])]);
        $target = new SchemaDefinition([
            new TableDefinition('workshops', columns: $columns, primaryKey: ['site', 'number']),
        ]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot reconcile a changed primary key for table "workshops".');
        new SchemaReconciler()->reconcile($current, $target);
    }

    public function test_changing_a_table_check_is_rejected_before_generating_sql(): void
    {
        $current = new SchemaDefinition([new TableDefinition('limits', checks: ['valid' => 'value > 0'])]);
        $target = new SchemaDefinition([new TableDefinition('limits', checks: ['valid' => 'value >= 0'])]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot reconcile changed check constraints for table "limits".');
        new SchemaReconciler()->reconcile($current, $target);
    }
}
