<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Filesystem\Filesystem;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\Migration\MigrationGenerator;
use Ultra\Schema\PolicyCommand;
use Ultra\Schema\PolicyMode;
use Ultra\Schema\PostgresFunctionDefinition;
use Ultra\Schema\PostgresPolicyDefinition;
use Ultra\Schema\RowLevelSecurity;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;
use Ultra\Schema\TableDefinition;
use Ultra\Schema\Tests\Support\MigrationTestCase;

final class PostgresPolicyMigrationTest extends MigrationTestCase
{
    public function test_a_policy_can_be_prepared_then_enforced_on_an_existing_table(): void
    {
        $previous = self::schema();
        $this->create($previous->tables[0]);
        $role = $this->createRole();
        $identifier = $this->connection->quoteSingleIdentifier($role);
        $this->connection->executeStatement('GRANT SELECT, INSERT ON documents TO ' . $identifier);
        $this->connection->executeStatement('INSERT INTO documents VALUES (1, ?), (2, ?)', [$role, 'someone else']);

        $policies = ['Owner "access"' => new PostgresPolicyDefinition(
            roles: [$role],
            using: 'owner_role = current_user::text',
        )];
        $active = self::schema(RowLevelSecurity::Enabled, $policies);
        $history = sys_get_temp_dir() . '/ultra_schema_rls_' . bin2hex(random_bytes(8));
        $generator = new MigrationGenerator($history);
        try {
            $generator->initialize($previous);
            $prepared = $generator->generate(self::schema(policies: $policies), 'prepare-policy');
            self::assertNotNull($prepared);
            $this->connection->executeStatement((string) file_get_contents(
                $history . '/' . $prepared . '/migration.sql',
            ));
            $this->connection->executeStatement('SET LOCAL ROLE ' . $identifier);
            self::assertSame([$role, 'someone else'], $this->visibleOwners());
            $this->connection->executeStatement('RESET ROLE');

            $enabled = $generator->generate($active, 'activate-rls');
            self::assertNotNull($enabled);
            $this->connection->executeStatement((string) file_get_contents(
                $history . '/' . $enabled . '/migration.sql',
            ));
            self::assertNull($generator->generate($active, 'unchanged'));
        } finally {
            new Filesystem()->remove($history);
        }

        $this->connection->executeStatement('SET LOCAL ROLE ' . $identifier);
        self::assertSame([$role], $this->visibleOwners());
        self::assertSame(1, $this->connection->executeStatement('INSERT INTO documents VALUES (3, ?)', [$role]));
        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('violates row-level security policy');
        $this->connection->executeStatement('INSERT INTO documents VALUES (4, ?)', ['someone else']);
    }

    public function test_policies_are_replaced_around_structural_changes_and_resolve_new_dependencies(): void
    {
        $previous = self::schema(policies: [
            'owner_access' => new PostgresPolicyDefinition(
                using: 'owner_role = current_user::text',
                withCheck: 'false',
                mode: PolicyMode::Restrictive,
            ),
            'obsolete' => new PostgresPolicyDefinition(),
        ]);
        $target = new SchemaDefinition([
            new TableDefinition(
                'documents',
                'archive',
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Int32),
                    new ColumnDefinition('owner_role', 'author', type: 'varchar(128)'),
                ],
                policies: [
                    'owner_access' => new PostgresPolicyDefinition(
                        command: PolicyCommand::Select,
                        using: 'matches_owner(author) AND EXISTS (SELECT 1 FROM settings WHERE active)',
                    ),
                ],
            ),
            new TableDefinition('settings', columns: [new ColumnDefinition('active', type: ColumnType::Boolean)]),
        ], functions: [new PostgresFunctionDefinition(
            'matches_owner',
            'matches_owner',
            ['author' => 'text'],
            'boolean',
            'sql',
            'SELECT author = current_user::text',
        )]);
        $reconciler = new SchemaReconciler();
        $this->execute($reconciler->reconcile(new SchemaDefinition([]), $previous));
        $this->connection->executeStatement("INSERT INTO documents VALUES (1, 'writer')");
        $this->execute($reconciler->reconcile($previous, $target));

        self::assertSame('writer', $this->connection->fetchOne('SELECT author FROM archive WHERE id = 1'));
        $policies = $this->connection->fetchAllAssociative(
            "SELECT policyname, permissive, cmd, with_check FROM pg_policies WHERE schemaname = current_schema() AND tablename = 'archive'",
        );
        self::assertSame(
            [[
                'policyname' => 'owner_access',
                'permissive' => 'PERMISSIVE',
                'cmd' => 'SELECT',
                'with_check' => null,
            ]],
            $policies,
        );
    }

    public function test_forced_rls_applies_to_the_owner_and_disabling_it_keeps_declared_policies(): void
    {
        $role = $this->connection->quoteSingleIdentifier($this->createRole());
        $policies = ['read_documents' => new PostgresPolicyDefinition(command: PolicyCommand::Select, using: 'false')];
        $forced = self::schema(RowLevelSecurity::Forced, $policies);
        $enabled = self::schema(RowLevelSecurity::Enabled, $policies);
        $disabled = self::schema(RowLevelSecurity::Disabled, $policies);
        $reconciler = new SchemaReconciler();
        $this->execute($reconciler->reconcile(new SchemaDefinition([]), $disabled));
        $this->connection->executeStatement("INSERT INTO documents VALUES (1, 'writer')");
        $this->connection->executeStatement('ALTER TABLE documents OWNER TO ' . $role);
        $this->connection->executeStatement('SET LOCAL ROLE ' . $role);
        $this->execute($reconciler->reconcile($disabled, $forced));
        self::assertSame([], $this->visibleOwners());

        $this->execute($reconciler->reconcile($forced, $enabled));
        self::assertSame(['writer'], $this->visibleOwners());
        $this->execute($reconciler->reconcile($enabled, $disabled));
        self::assertFalse($this->connection->fetchOne(
            "SELECT relrowsecurity FROM pg_class WHERE oid = 'documents'::regclass",
        ));
        self::assertSame(
            'read_documents',
            $this->connection->fetchOne("SELECT polname FROM pg_policy WHERE polrelid = 'documents'::regclass"),
        );
    }

    public function test_enabling_rls_without_policies_denies_access_by_default(): void
    {
        $target = self::schema(RowLevelSecurity::Enabled);
        $this->execute(new SchemaReconciler()->reconcile(new SchemaDefinition([]), $target));
        $this->connection->executeStatement("INSERT INTO documents VALUES (1, 'writer')");
        $role = $this->connection->quoteSingleIdentifier($this->createRole());
        $this->connection->executeStatement('GRANT SELECT ON documents TO ' . $role);
        $this->connection->executeStatement('SET LOCAL ROLE ' . $role);

        self::assertSame([], $this->visibleOwners());
    }

    private function createRole(): string
    {
        $role = 'RLS "reader" ' . bin2hex(random_bytes(6));
        $identifier = $this->connection->quoteSingleIdentifier($role);
        $schema = $this->connection->quoteSingleIdentifier((string) $this->connection->fetchOne(
            'SELECT current_schema()',
        ));
        $this->connection->executeStatement('CREATE ROLE ' . $identifier);
        $this->connection->executeStatement('GRANT ' . $identifier . ' TO SESSION_USER WITH SET TRUE');
        $this->connection->executeStatement('GRANT USAGE, CREATE ON SCHEMA ' . $schema . ' TO ' . $identifier);

        return $role;
    }

    /** @return list<string> */
    private function visibleOwners(): array
    {
        return $this->connection->fetchFirstColumn('SELECT owner_role FROM documents ORDER BY id');
    }

    /** @param array<string, PostgresPolicyDefinition> $policies */
    private static function schema(?RowLevelSecurity $mode = null, array $policies = []): SchemaDefinition
    {
        return new SchemaDefinition([new TableDefinition(
            'documents',
            columns: [
                new ColumnDefinition('id', type: ColumnType::Int32),
                new ColumnDefinition('owner_role', type: ColumnType::Text),
            ],
            rowLevelSecurity: $mode,
            policies: $policies,
        )]);
    }
}
