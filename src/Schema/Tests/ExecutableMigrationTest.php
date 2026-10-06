<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\Operations\AddColumn;
use Ultra\Schema\Operations\AddIndex;
use Ultra\Schema\Operations\AlterColumn;
use Ultra\Schema\Operations\CreateTable;
use Ultra\Schema\Operations\DropColumn;
use Ultra\Schema\Operations\DropIndex;
use Ultra\Schema\Operations\DropTable;
use Ultra\Schema\Operations\RenameColumn;
use Ultra\Schema\Operations\RenameIndex;
use Ultra\Schema\Operations\RenameTable;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;
use Ultra\Schema\TableDefinition;
use Ultra\Schema\Tests\Support\MigrationTestCase;

/** @internal */
#[CoversClass(PostgresSqlGenerator::class)]
#[CoversClass(SchemaReconciler::class)]
#[CoversClass(ColumnDefinition::class)]
#[CoversClass(IndexDefinition::class)]
#[CoversClass(TableDefinition::class)]
#[CoversClass(SchemaDefinition::class)]
final class ExecutableMigrationTest extends MigrationTestCase
{
    public function test_renames_keep_data_and_dependent_operations_use_resolved_names(): void
    {
        $current = new TableDefinition('t', 'users', [
            new ColumnDefinition('id', type: 'uuid'), new ColumnDefinition('name', 'full_name', 'text'),
            new ColumnDefinition('score', type: 'integer', defaultExpression: '0'), new ColumnDefinition('retired', type: 'text', nullable: true),
        ], [new IndexDefinition('lookup', 'users_name_idx', ['full_name']), new IndexDefinition('retired-idx', 'users_id_idx', ['id'])]);
        $created = new ColumnDefinition('created', 'created_at', 'timestamp with time zone', defaultExpression: 'now()');
        $target = new TableDefinition('t', 'app"users', [
            $current->columns[0], new ColumnDefinition('name', 'display"name', 'text'),
            new ColumnDefinition('score', 'points', 'bigint', nullable: true, defaultExpression: '42'),
            $created, new ColumnDefinition('extra', type: 'text', nullable: true),
        ], [new IndexDefinition('lookup', 'app"name_idx', ['display"name'], unique: true)]);
        $this->create($current);
        $this->connection->executeStatement("INSERT INTO users (id, full_name) VALUES ('00000000-0000-0000-0000-000000000001', 'Ada')");
        $operations = new SchemaReconciler()->reconcile(new SchemaDefinition([$current]), new SchemaDefinition([$target]));
        self::assertEquals([
            new RenameTable('users', 'app"users'), new RenameColumn('app"users', 'full_name', 'display"name'),
            new RenameColumn('app"users', 'score', 'points'), new AlterColumn('app"users', 'points', 'bigint', true, '42'),
            new AddColumn('app"users', $created), new AddColumn('app"users', $target->columns[4]), new DropColumn('app"users', 'retired'),
            new RenameIndex('app"users', 'users_name_idx', 'app"name_idx'), new DropIndex('app"users', 'app"name_idx'),
            new AddIndex('app"users', $target->indexes[0]), new DropIndex('app"users', 'users_id_idx'),
        ], $operations);
        $this->execute($operations);
        self::assertSame(['display"name' => 'Ada', 'points' => 0, 'extra' => null], $this->connection->fetchAssociative('SELECT "display""name", points, extra FROM "app""users"'));
        self::assertTrue($this->connection->fetchOne('SELECT created_at IS NOT NULL FROM "app""users"'));
        self::assertSame(['app"name_idx'], $this->connection->fetchFirstColumn('SELECT indexname FROM pg_indexes WHERE schemaname = current_schema()'));
        self::assertTrue($this->connection->fetchOne("SELECT indisunique FROM pg_index JOIN pg_class ON indexrelid = oid WHERE relname = ? AND relnamespace = current_schema()::regnamespace", ['app"name_idx']));
        self::assertSame('managed by: ultra', $this->connection->fetchOne("SELECT obj_description(oid, 'pg_class') FROM pg_class WHERE relname = ? AND relnamespace = current_schema()::regnamespace", ['app"name_idx']));
        self::assertSame('managed by: ultra', $this->connection->fetchOne("SELECT col_description(attrelid, attnum) FROM pg_attribute JOIN pg_class ON attrelid = oid WHERE relname = ? AND attname = 'created_at' AND relnamespace = current_schema()::regnamespace", ['app"users']));
        self::assertSame([], new SchemaReconciler()->reconcile(new SchemaDefinition([$target]), new SchemaDefinition([$target])));
    }

    public function test_removed_identities_are_dropped_and_new_identities_are_created(): void
    {
        $current = new TableDefinition('t', 'users', [new ColumnDefinition('keep', type: 'text'), new ColumnDefinition('old', type: 'text')], [
            new IndexDefinition('keep', 'keep_idx', ['keep']), new IndexDefinition('old', 'old_idx', ['keep']),
        ]);
        $new = new TableDefinition('new', 'accounts', [new ColumnDefinition('id', type: 'uuid')]);
        $target = new TableDefinition('t', 'users', [$current->columns[0], new ColumnDefinition('new', type: 'bigint', nullable: true)], [
            new IndexDefinition('new', 'new_idx', ['keep']), new IndexDefinition('keep', 'keep_renamed_idx', ['keep']),
        ]);
        $this->create($current);
        $this->create(new TableDefinition('old', 'retired', [new ColumnDefinition('id', type: 'uuid')]));
        $operations = new SchemaReconciler()->reconcile(new SchemaDefinition([$current, new TableDefinition('old', 'retired')]), new SchemaDefinition([$new, $target]));
        self::assertEqualsCanonicalizing([
            new AddColumn('users', $target->columns[1]), new DropColumn('users', 'old'),
            new AddIndex('users', $target->indexes[0]), new RenameIndex('users', 'keep_idx', 'keep_renamed_idx'),
            new DropIndex('users', 'old_idx'), new CreateTable($new), new DropTable('retired'),
        ], $operations);
        $this->execute($operations);
        self::assertSame(['accounts', 'users'], $this->connection->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename"));
        self::assertSame(['keep', 'new'], $this->connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'users' ORDER BY ordinal_position"));
        self::assertSame(['keep_renamed_idx', 'new_idx'], $this->connection->fetchFirstColumn('SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() ORDER BY indexname'));
    }

    /** @param array<string, mixed> $changes @param array<string, mixed> $before */
    #[DataProvider('columnChanges')]
    public function test_alterations_preserve_values_and_apply_types_nullability_and_defaults(array $changes, ?AlterColumn $expected, string $type, bool $nullable, ?string $default, array $before = []): void
    {
        $current = new TableDefinition('t', 'scores', [new ColumnDefinition('c', ...($before + ['name' => 'score', 'type' => 'integer', 'defaultExpression' => '0']))]);
        $target = new TableDefinition('t', 'scores', [new ColumnDefinition('c', ...($changes + ['name' => 'score', 'type' => 'integer', 'defaultExpression' => '0']))]);
        $this->create($current);
        $this->connection->executeStatement('INSERT INTO scores (score) VALUES (0)');
        $operations = new SchemaReconciler()->reconcile(new SchemaDefinition([$current]), new SchemaDefinition([$target]));
        self::assertEquals(null === $expected ? [] : [$expected], $operations);
        $this->execute($operations);
        self::assertSame(['type' => $type, 'nullable' => $nullable, 'default' => $default], $this->connection->fetchAssociative(<<<'SQL'
            SELECT format_type(atttypid, atttypmod) AS type, NOT attnotnull AS nullable, pg_get_expr(adbin, adrelid) AS "default"
            FROM pg_attribute LEFT JOIN pg_attrdef ON attrelid = adrelid AND attnum = adnum
            WHERE attrelid = 'scores'::regclass AND attname = 'score'
            SQL));
        self::assertSame(0, $this->connection->fetchOne('SELECT score FROM scores'));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: AlterColumn|null, 2: string, 3: bool, 4: string|null, 5?: array<string, mixed>}> */
    public static function columnChanges(): iterable
    {
        yield 'unchanged' => [[], null, 'integer', false, '0'];
        yield 'type only' => [['type' => 'bigint'], new AlterColumn('scores', 'score', type: 'bigint'), 'bigint', false, '0'];
        yield 'nullable only' => [['nullable' => true], new AlterColumn('scores', 'score', nullable: true), 'integer', true, '0'];
        yield 'required only' => [['nullable' => false], new AlterColumn('scores', 'score', nullable: false), 'integer', false, '0', ['nullable' => true]];
        yield 'introduce default' => [['defaultExpression' => '42'], new AlterColumn('scores', 'score', defaultExpression: '42'), 'integer', false, '42', ['defaultExpression' => null]];
        yield 'default only' => [['defaultExpression' => '42'], new AlterColumn('scores', 'score', defaultExpression: '42'), 'integer', false, '42'];
        yield 'drop default only' => [['defaultExpression' => null], new AlterColumn('scores', 'score', dropDefault: true), 'integer', false, null];
        yield 'all attributes' => [['type' => 'bigint', 'nullable' => true, 'defaultExpression' => null], new AlterColumn('scores', 'score', 'bigint', true, dropDefault: true), 'bigint', true, null];
    }

    public function test_created_columns_keep_postgres_types_constraints_defaults_and_managed_comments(): void
    {
        $this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS hstore');
        $this->create(new TableDefinition('events', columns: [
            new ColumnDefinition('sequence', type: 'BIGINT', nullable: true, constraints: ['GENERATED BY DEFAULT AS IDENTITY', 'PRIMARY KEY']),
            new ColumnDefinition('xid', type: 'XID8', defaultExpression: 'pg_current_xact_id()'),
            new ColumnDefinition('event_type', type: 'TEXT'), new ColumnDefinition('payload', type: 'JSONB'),
            new ColumnDefinition('domain_ids', type: 'HSTORE'), new ColumnDefinition('recorded_at', type: 'TIMESTAMPTZ', defaultExpression: 'statement_timestamp()'),
        ]));
        $this->connection->executeStatement("INSERT INTO events(event_type, payload, domain_ids) VALUES ('created', '{\"x\":1}', 'id=>one')");
        self::assertSame([1, 'created', '{"x": 1}', '"id"=>"one"'], array_values($this->connection->fetchAssociative('SELECT sequence, event_type, payload::text, domain_ids::text FROM events')));
        self::assertTrue($this->connection->fetchOne('SELECT xid = pg_current_xact_id() AND recorded_at IS NOT NULL FROM events'));
        self::assertSame(['bigint', 'xid8', 'text', 'jsonb', 'hstore', 'timestamp with time zone'], $this->connection->fetchFirstColumn("SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = 'events'::regclass AND attnum > 0 ORDER BY attnum"));
        self::assertSame(['sequence'], $this->connection->fetchFirstColumn("SELECT attname FROM pg_attribute WHERE attrelid = 'events'::regclass AND attidentity = 'd'"));
        self::assertSame('p', $this->connection->fetchOne("SELECT contype FROM pg_constraint WHERE conrelid = 'events'::regclass AND contype = 'p'"));
        self::assertSame('managed by: ultra', $this->connection->fetchOne("SELECT obj_description('events'::regclass, 'pg_class')"));
        self::assertSame(array_fill(0, 6, 'managed by: ultra'), $this->connection->fetchFirstColumn("SELECT col_description(attrelid, attnum) FROM pg_attribute WHERE attrelid = 'events'::regclass AND attnum > 0 ORDER BY attnum"));
    }

    #[DataProvider('rawIndexes')]
    public function test_raw_index_statements_are_executable_and_keep_options_and_comments(string $sql): void
    {
        $this->create(new TableDefinition('documents', columns: [new ColumnDefinition('locations', type: 'jsonb')]));
        $this->execute([new AddIndex('documents', new IndexDefinition('i', 'gin_idx', rawSql: $sql))]);
        self::assertSame(['access_method' => 'gin', 'options' => '{fastupdate=off}', 'comment' => 'managed by: ultra'], $this->connection->fetchAssociative(<<<'SQL'
            SELECT amname AS access_method, reloptions::text AS options, obj_description(c.oid, 'pg_class') AS comment
            FROM pg_class c JOIN pg_am ON relam = pg_am.oid WHERE relname = 'gin_idx' AND relnamespace = current_schema()::regnamespace
            SQL));
    }

    /** @return iterable<string, array{string}> */
    public static function rawIndexes(): iterable
    {
        yield 'unterminated' => ['CREATE INDEX gin_idx ON documents USING GIN (locations) WITH (fastupdate = off)'];
        yield 'terminated with surrounding whitespace' => [" \nCREATE INDEX gin_idx ON documents USING GIN (locations) WITH (fastupdate = off); \n"];
    }
}
