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

        self::assertFileDoesNotExist($this->directory . '/' . $first . '/snapshot.lock');
        $snapshot = $this->snapshot('migrations');
        self::assertSame(3, $snapshot['format']);
        $firstRevision = $snapshot['revision'];
        self::assertStringStartsWith('000001-create-tables:', $firstRevision);
        self::assertNull($this->metadata($first)['previous']);
        self::assertTrue($snapshot['tables'][0]['hasExplicitName']);
        self::assertFalse($snapshot['tables'][1]['hasExplicitName']);

        // Optional schema fields can be omitted from a snapshot.
        $snapshot = json_decode($this->read('migrations/snapshot.lock'), flags: JSON_THROW_ON_ERROR);
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
        file_put_contents($this->directory . '/migrations/snapshot.lock', json_encode($snapshot, JSON_THROW_ON_ERROR));

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
        self::assertSame($firstRevision, $this->metadata($second)['previous']);
        self::assertSame($this->metadata($second)['revision'], $this->snapshot('migrations')['revision']);
        self::assertFileDoesNotExist($this->directory . '/' . $second . '/snapshot.lock');

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
        $baselineSnapshot = $this->read($baseline . '/snapshot.lock');
        self::assertSame($baselineSnapshot, $this->read('migrations/snapshot.lock'));
        self::assertFileDoesNotExist($this->directory . '/' . $baseline . '/migration.sql');
        self::assertSame(1, $this->command('init', 'schema.php', 'migrations')->run());

        $this->writeDefinition(['$includeObsolete = true;' => '$includeObsolete = false;']);

        $arguments = ['generate', 'schema.php', 'migrations', 'drop-obsolete'];
        $refused = $this->command(...$arguments);
        self::assertSame(1, $refused->run());
        self::assertStringContainsString('Drop column "accounts"."email"', $refused->getErrorOutput());
        self::assertStringContainsString('Drop table "receipt"', $refused->getErrorOutput());
        self::assertSame(['.', '..', '000000-baseline', 'snapshot.lock'], scandir($this->directory . '/migrations'));

        $this->command('generate', '--allow-destructive', 'schema.php', 'migrations', 'drop-obsolete')->mustRun();
        $migration = 'migrations/000001-drop-obsolete';
        $sql = $this->read($migration . '/migration.sql');
        self::assertStringContainsString('DROP TABLE "receipt";', $sql);
        self::assertStringContainsString('ALTER TABLE "accounts" DROP COLUMN "email";', $sql);
        self::assertSame($this->snapshot($baseline)['revision'], $this->metadata($migration)['previous']);
        self::assertSame($baselineSnapshot, $this->read($baseline . '/snapshot.lock'));
    }

    public function test_refuses_two_migrations_generated_from_the_same_predecessor(): void
    {
        $this->command('generate', 'schema.php', 'migrations', 'create-tables')->mustRun();
        $filesystem = new Filesystem();
        $filesystem->copy($this->directory . '/migrations/snapshot.lock', $this->directory . '/base.json');
        $filesystem->mirror($this->directory . '/migrations', $this->directory . '/branch');

        $this->writeDefinition(["'account', 'accounts'" => "'account', 'members'"]);
        $this->command('generate', 'schema.php', 'migrations', 'rename-accounts')->mustRun();
        $this->writeDefinition(["'receipt'," => "'receipt', 'invoice',"]);
        $this->command('generate', 'schema.php', 'branch', 'rename-receipt')->mustRun();

        $merge = new Process([
            'git',
            'merge-file',
            '-p',
            'migrations/snapshot.lock',
            'base.json',
            'branch/snapshot.lock',
        ], $this->directory);
        self::assertSame(1, $merge->run(), $merge->getErrorOutput());
        self::assertMatchesRegularExpression('/^<<<<<<<[^\n]*\n    "revision":/m', $merge->getOutput());

        $filesystem->mirror(
            $this->directory . '/branch/000002-rename-receipt',
            $this->directory . '/migrations/000002-rename-receipt',
        );
        $refused = $this->command('validate', 'migrations');
        self::assertSame(1, $refused->run());
        self::assertStringContainsString('divergent migration history', $refused->getErrorOutput());

        $filesystem->rename(
            $this->directory . '/migrations/000002-rename-receipt',
            $this->directory . '/migrations/000003-rename-receipt',
        );
        self::assertSame(1, $refused->run());
        self::assertStringContainsString('predecessor "000002-rename-accounts:', $refused->getErrorOutput());
        self::assertStringContainsString('000001-create-tables:', $refused->getErrorOutput());
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
        $snapshot = $this->snapshot('migrations');
        $snapshot['format']++;
        file_put_contents($this->directory . '/migrations/snapshot.lock', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertSame(1, $refused->run());
        self::assertStringContainsString('Unsupported schema snapshot format', $refused->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->directory . '/migrations/000002-continue');
    }

    public function test_validate_rejects_a_missing_stale_or_edited_root_snapshot_without_writing_files(): void
    {
        $validate = $this->command('validate', 'migrations');
        self::assertStringContainsString('Migration history is valid.', $validate->mustRun()->getOutput());
        self::assertDirectoryDoesNotExist($this->directory . '/migrations');
        $this->command('generate', 'schema.php', 'migrations', 'create-tables')->mustRun();
        $path = $this->directory . '/migrations/snapshot.lock';
        $first = $this->read('migrations/snapshot.lock');
        $this->writeDefinition(["'account', 'accounts'" => "'account', 'members'"]);
        $this->command('generate', 'schema.php', 'migrations', 'rename-tables')->mustRun();
        $second = $this->read('migrations/snapshot.lock');

        unlink($path);
        self::assertSame(1, $validate->run());
        self::assertStringContainsString('root snapshot does not match', $validate->getErrorOutput());
        self::assertFileDoesNotExist($path);

        file_put_contents($path, $first);
        self::assertSame(1, $validate->run());
        self::assertStringContainsString('root snapshot does not match', $validate->getErrorOutput());
        self::assertSame($first, $this->read('migrations/snapshot.lock'));

        $edited = str_replace('"members"', '"altered"', $second);
        file_put_contents($path, $edited);
        self::assertSame(1, $validate->run());
        self::assertStringContainsString('revision does not match its contents', $validate->getErrorOutput());
        self::assertSame($edited, $this->read('migrations/snapshot.lock'));

        file_put_contents($path, $second);
        $validate->mustRun();
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
        return json_decode($this->read($entry . '/snapshot.lock'), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function metadata(string $entry): array
    {
        return json_decode($this->read($entry . '/migration.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->directory . '/' . $path);
    }
}
