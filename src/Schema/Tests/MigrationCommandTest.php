<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class MigrationCommandTest extends TestCase
{
    private const string SCHEMA = <<<'PHP_SOURCE'
        <?php

        use Ultra\Schema\ColumnDefinition;
        use Ultra\Schema\SqlType;
        use Ultra\Schema\ColumnType;
        use Ultra\Schema\ForeignKeyDefinition;
        use Ultra\Schema\ForeignKeyTiming;
        use Ultra\Schema\IndexDefinition;
        use Ultra\Schema\SchemaDefinition;
        use Ultra\Schema\TableDefinition;

        $includeObsolete = true;

        return new SchemaDefinition([
            new TableDefinition(
                'account', 'accounts',
                columns: [
                    new ColumnDefinition('tenant', type: ColumnType::Int32),
                    new ColumnDefinition('login', 'username', type: SqlType::fromSql('varchar(50)'), constraints: ['COLLATE "C"']),
                    ...($includeObsolete ? [
                        new ColumnDefinition('email', type: ColumnType::Text, nullable: true, defaultExpression: "'none'"),
                    ] : []),
                ],
                indexes: $includeObsolete ? [
                    new IndexDefinition('email', 'accounts_email', columns: ['email'], unique: true),
                ] : [],
                primaryKey: ['tenant', 'login'],
                checks: ['tenant_positive' => 'tenant > 0'],
            ),
            ...($includeObsolete ? [new TableDefinition(
                'receipt',
                columns: [
                    new ColumnDefinition('login', type: SqlType::fromSql('varchar(50)')),
                    new ColumnDefinition('tenant', type: ColumnType::Int32),
                ],
                indexes: [
                    new IndexDefinition('receipt_lookup', rawSql: 'CREATE INDEX "receipt_lookup" ON "receipt" (lower("login"))'),
                ],
                foreignKeys: [
                    'receipt_account' => new ForeignKeyDefinition(
                        'account', ['tenant' => 'tenant', 'login' => 'login'], ForeignKeyTiming::InitiallyDeferred,
                    ),
                ],
            )] : []),
        ]);
        PHP_SOURCE;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/ultra_schema_migration_' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->writeDefinition();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function test_generate_help_describes_arguments_and_destructive_option(): void
    {
        $help = $this->command('generate', '--help')->mustRun()->getOutput();

        self::assertStringContainsString('<schema> <history-directory> <name>', $help);
        self::assertStringContainsString('--allow-destructive', $help);
    }

    public function test_generates_sql_from_the_last_recorded_definition_and_keeps_rename_identities(): void
    {
        $this->command('generate', 'schema.php', 'migrations', 'create-tables')->mustRun();
        $first = 'migrations/000001-create-tables';
        self::assertStringContainsString('CREATE TABLE "accounts"', $this->read($first . '/migration.sql'));
        self::assertStringContainsString('DEFERRABLE INITIALLY DEFERRED', $this->read($first . '/migration.sql'));

        $snapshot = $this->snapshot($first);
        self::assertSame(2, $snapshot['format']);
        self::assertNull($snapshot['previous']);
        self::assertTrue($snapshot['tables'][0]['hasExplicitName']);
        self::assertFalse($snapshot['tables'][1]['hasExplicitName']);

        // Optional schema fields can be omitted from a snapshot.
        $snapshot = json_decode($this->read($first . '/snapshot.json'), flags: JSON_THROW_ON_ERROR);
        unset($snapshot->requiredExtensions, $snapshot->functions);
        foreach ($snapshot->tables as $table) {
            unset($table->rowLevelSecurity, $table->policies);
            foreach ($table->indexes as $index) {
                unset($index->method);
            }
            foreach ($table->foreignKeys as $foreignKey) {
                unset($foreignKey->onDelete);
            }
        }
        file_put_contents(
            $this->directory . '/' . $first . '/snapshot.json',
            json_encode($snapshot, JSON_THROW_ON_ERROR),
        );

        $this->writeDefinition([
            "'account', 'accounts'" => "'account', 'members'",
            "'login', 'username'" => "'login', 'handle'",
        ]);
        $this->command('generate', 'schema.php', 'migrations', 'rename-tables')->mustRun();
        $second = 'migrations/000002-rename-tables';
        $sql = $this->read($second . '/migration.sql');
        self::assertStringContainsString('ALTER TABLE "accounts" RENAME TO "members";', $sql);
        self::assertStringContainsString('ALTER TABLE "members" RENAME COLUMN "username" TO "handle";', $sql);
        self::assertStringNotContainsString('DROP ', $sql);
        self::assertSame('000001-create-tables', $this->snapshot($second)['previous']);

        $unchanged = $this->command('generate', 'schema.php', 'migrations', 'unchanged')->mustRun();
        self::assertStringContainsString('No schema changes.', $unchanged->getOutput());
        self::assertDirectoryDoesNotExist($this->directory . '/migrations/000003-unchanged');
    }

    public function test_equivalent_type_spellings_do_not_generate_a_migration_but_precision_changes_do(): void
    {
        $this->writeDefinition([
            'type: ColumnType::Text, nullable' => "type: SqlType::fromSql('timestamptz'), nullable",
            ", defaultExpression: \"'none'\"" => '',
        ]);
        $this->command('generate', 'schema.php', 'migrations', 'create-tables')->mustRun();

        $this->writeDefinition([
            'type: ColumnType::Int32' => "type: SqlType::fromSql('INT4')",
            ", defaultExpression: \"'none'\"" => '',
            'type: ColumnType::Text, nullable' => "type: SqlType::fromSql('timestamp(6) with time zone'), nullable",
        ]);
        $result = $this->command('generate', 'schema.php', 'migrations', 'aliases')->mustRun();
        self::assertStringContainsString('No schema changes.', $result->getOutput());
        self::assertDirectoryDoesNotExist($this->directory . '/migrations/000002-aliases');

        $this->writeDefinition([
            'type: ColumnType::Int32' => "type: SqlType::fromSql('INT4')",
            ", defaultExpression: \"'none'\"" => '',
            'type: ColumnType::Text, nullable' => "type: SqlType::fromSql('timestamp(3) with time zone'), nullable",
        ]);
        $this->command('generate', 'schema.php', 'migrations', 'change-precision')->mustRun();
        self::assertStringContainsString(
            'ALTER TABLE "accounts" ALTER COLUMN "email" TYPE timestamp(3) with time zone;',
            $this->read('migrations/000002-change-precision/migration.sql'),
        );
    }

    public function test_adopts_an_existing_definition_and_requires_approval_for_table_and_column_removals(): void
    {
        $this->command('init', 'schema.php', 'migrations')->mustRun();
        $baseline = 'migrations/000000-baseline';
        self::assertNull($this->snapshot($baseline)['previous']);
        self::assertFileDoesNotExist($this->directory . '/' . $baseline . '/migration.sql');
        self::assertSame(1, $this->command('init', 'schema.php', 'migrations')->run());

        $this->writeDefinition(['$includeObsolete = true;' => '$includeObsolete = false;']);

        $arguments = ['generate', 'schema.php', 'migrations', 'drop-obsolete'];
        $refused = $this->command(...$arguments);
        self::assertSame(1, $refused->run());
        self::assertStringContainsString('Drop column "accounts"."email"', $refused->getErrorOutput());
        self::assertStringContainsString('Drop table "receipt"', $refused->getErrorOutput());
        self::assertSame(['.', '..', '000000-baseline'], scandir($this->directory . '/migrations'));

        $this->command('generate', '--allow-destructive', 'schema.php', 'migrations', 'drop-obsolete')->mustRun();
        $migration = 'migrations/000001-drop-obsolete';
        $sql = $this->read($migration . '/migration.sql');
        self::assertStringContainsString('DROP TABLE "receipt";', $sql);
        self::assertStringContainsString('ALTER TABLE "accounts" DROP COLUMN "email";', $sql);
        self::assertSame('000000-baseline', $this->snapshot($migration)['previous']);
    }

    public function test_refuses_two_migrations_generated_from_the_same_predecessor(): void
    {
        $this->command('generate', 'schema.php', 'migrations', 'create-tables')->mustRun();
        $filesystem = new Filesystem();
        $filesystem->mirror($this->directory . '/migrations', $this->directory . '/branch');

        $this->writeDefinition(["'account', 'accounts'" => "'account', 'members'"]);
        $this->command('generate', 'schema.php', 'migrations', 'rename-accounts')->mustRun();
        $this->writeDefinition(["'login', 'username'" => "'login', 'handle'"]);
        $this->command('generate', 'schema.php', 'branch', 'rename-column')->mustRun();

        $filesystem->mirror(
            $this->directory . '/branch/000002-rename-column',
            $this->directory . '/migrations/000002-rename-column',
        );
        $refused = $this->command('generate', 'schema.php', 'migrations', 'continue');
        self::assertSame(1, $refused->run());
        self::assertStringContainsString('divergent migration history', $refused->getErrorOutput());

        $filesystem->rename(
            $this->directory . '/migrations/000002-rename-column',
            $this->directory . '/migrations/000003-rename-column',
        );
        self::assertSame(1, $refused->run());
        self::assertStringContainsString('predecessor "000002-rename-accounts"', $refused->getErrorOutput());
        self::assertStringContainsString('found "000001-create-tables"', $refused->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory . '/migrations/000004-continue');
    }

    public function test_refuses_incomplete_entries_and_unsupported_snapshot_formats(): void
    {
        $this->command('generate', 'schema.php', 'migrations', 'create-tables')->mustRun();
        $path = 'migrations/000001-create-tables';
        $sql = $this->read($path . '/migration.sql');
        unlink($this->directory . '/' . $path . '/migration.sql');
        $refused = $this->command('generate', 'schema.php', 'migrations', 'continue');
        self::assertSame(1, $refused->run());
        self::assertStringContainsString('Incomplete or invalid migration entry', $refused->getErrorOutput());

        file_put_contents($this->directory . '/' . $path . '/migration.sql', $sql);
        $snapshot = $this->snapshot($path);
        $snapshot['format']++;
        file_put_contents(
            $this->directory . '/' . $path . '/snapshot.json',
            json_encode($snapshot, JSON_THROW_ON_ERROR),
        );
        self::assertSame(1, $refused->run());
        self::assertStringContainsString('Unsupported schema snapshot format', $refused->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory . '/migrations/000002-continue');
    }

    /** @param array<string, string> $replacements */
    private function writeDefinition(array $replacements = []): void
    {
        file_put_contents($this->directory . '/schema.php', strtr(self::SCHEMA, $replacements));
    }

    private function command(string ...$arguments): Process
    {
        return new Process([
            PHP_BINARY,
            dirname(__DIR__) . '/Runtime/bin/ultra-schema',
            ...$arguments,
        ], $this->directory);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $entry): array
    {
        return json_decode($this->read($entry . '/snapshot.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->directory . '/' . $path);
    }
}
