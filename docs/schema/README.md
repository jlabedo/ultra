# Schema

Schema defines relational tables and generates reviewable PostgreSQL migrations. Applications supply their
own definitions and can compose them from fragments.

## Generate a migration

### From Symfony

With `UltraBundle` and [autoconfigured schema fragments](#symfony-discovery), run:

```bash
bin/console ultra:schema:generate create-tables
```

The command gets its target definition from the compiled container and compares it offline with the
latest snapshot in `migrations/schema` under the project directory. It accepts `--allow-destructive`.
To use another history directory, set `ultra.schema.migrations_directory` in the bundle configuration.
Relative paths are resolved from the project directory.

```yaml
# config/packages/ultra.yaml
ultra:
    schema:
        migrations_directory: database/ultra
```

### From a PHP definition

Create a PHP file that returns the target `SchemaDefinition`. It can call the same fragment methods that
[Symfony discovery](#symfony-discovery) uses. Run the Composer binary from the application:

```bash
vendor/bin/ultra-schema generate config/schema.php migrations/schema create-tables
```

The command uses Symfony Console 7.4 or 8.x. Run `vendor/bin/ultra-schema generate --help` to see the
arguments and options. Generation starts from an empty schema when the history is empty. To use the
same history for existing and fresh databases, [generate an initial SQL migration](#adopt-an-existing-database).
For a snapshot-only baseline of an existing database, pass a definition of its current Ultra-managed tables:

```bash
vendor/bin/ultra-schema init config/schema.php migrations/schema
```

`init` records `000000-baseline/snapshot.lock` and initializes the root `snapshot.lock` in an empty
history. Verify the baseline against the database before generating further changes. Keep the baseline
snapshot intact as the root snapshot advances.

Both `snapshot.lock` files contain JSON. For an existing history in this layout, rename any
`snapshot.json` at the root and in the baseline directory to `snapshot.lock` without changing its
contents. This preserves the recorded baseline checksum.

### Review and validate

Each generation compares the target with the root `snapshot.lock`, including when migrations are still
pending deployment. It updates that snapshot and creates a numbered directory such as
`000001-create-tables` with `migration.sql` and `migration.lock`. Use lowercase letters and digits
separated by hyphens for the migration name. The root snapshot retains stable table, column and index
identities for renames. Each `migration.lock` records the predecessor and resulting revision in JSON.
For an existing history in this layout, rename each `migration.json` to `migration.lock`.
A diff with no operations creates no entry.

For table or column drops, the command lists the affected objects and exits before writing an entry.
Review the target definition and rerun with `--allow-destructive` to generate the SQL if the drops are intended.

Review and commit the migration directory and root snapshot together. If review changes the resulting
structure of a declared table, update the target definition and regenerate the pending entry before
adding back any manual SQL. Apply the forward SQL in numbered order with the Symfony migration commands
or through deployment tooling that tracks applied migrations.

The root snapshot puts the migration ID and a fingerprint of the resulting definition on one
`revision` line. Git can detect a merge conflict on this line even when the branches changed different
tables. Resolve a divergent history by taking the root snapshot from the updated base branch and
regenerating your unapplied migrations in order. Carry over any reviewed SQL edits.

Run the history check locally or in CI on the result of a merge:

```bash
bin/console ultra:schema:validate
vendor/bin/ultra-schema validate migrations/schema
```

Both commands check numbering, predecessor revisions, required files and the root snapshot against
the last entry. Validation works offline and leaves the history files untouched.

### Apply from Symfony

The commands resolve their PostgreSQL DBAL connection when run. They use `database_connection` by
default, the alias for the connection selected by DoctrineBundle. Set `connection` to override it
or to use a custom `doctrine/dbal` service without DoctrineBundle.

```yaml
ultra:
    schema:
        connection: app.migrations_connection
```

The commands use the same `migrations_directory` as `generate`. The metadata table defaults to
`public.ultra_schema_migrations`. Its schema must already exist. An unqualified metadata table name
uses `public`. Set the connection's `search_path` for unqualified names in the migration SQL.

```bash
bin/console ultra:schema:status
bin/console ultra:schema:migrate
```

`status` reports applied and pending entries without creating the journal. `migrate` takes a
PostgreSQL advisory lock for the journal and records each entry in the same transaction as its SQL.
A retry resumes at the first pending entry. The journal records the entry ID, execution time,
and a SHA-256 checksum of each applied SQL file or of the baseline snapshot. Applied entries must form an unchanged prefix of the
versioned history. Keep these files intact and commit corrections as a later migration.

Scripts must run within one PostgreSQL transaction. Leave transaction control to the runner.
Statements that require execution outside a transaction, such as `CREATE INDEX CONCURRENTLY`,
are unsupported. Use a connection with a stable PostgreSQL session for the advisory lock.

If the history starts with `000000-baseline`, verify that the target database matches that snapshot
and run `bin/console ultra:schema:baseline` before `migrate`. This records the baseline in the
journal without writing to the application tables. An existing baseline describes an already
provisioned database. For a fresh database, use a history that starts with a creation migration.

### Adopt an existing database

To use one history for an existing database and fresh environments, declare the existing schema
that Ultra will manage, then generate and commit the first migration from an empty history:

```bash
bin/console ultra:schema:generate initial-schema
```

On each existing database, verify that it already matches the initial migration's result. Then
register the exact migration ID before applying later migrations:

```bash
bin/console ultra:schema:adopt 000001-initial-schema
bin/console ultra:schema:migrate
```

`adopt` accepts only the first SQL migration of a history with an empty journal. It records the
migration ID and SQL checksum as applied without executing the SQL or inspecting the application
schema. On an empty test database, run `ultra:schema:migrate` directly to execute the initial
script and all later migrations.

### Coexist with Doctrine

Schema reconciles the objects declared in its snapshots and supplied definition. An application can keep
separate Schema and Doctrine histories on the same PostgreSQL database. Assign each table to one
generator and configure Doctrine's schema introspection to exclude tables outside its scope. An
application can use its own naming or ownership convention. Add foreign keys across the two scopes
in a hand-written migration and account for their dependencies when ordering deployment.
Schema marks managed tables, columns and indexes with `managed-by:ultra`.

To transfer an existing Doctrine table, declare its current structure in Schema and generate a
dedicated adoption entry. Keep the verified root snapshot and migration metadata, and replace the table-creation SQL with a
`COMMENT ON TABLE ... IS 'managed-by:ultra'` statement. Apply the adoption entry before the next
Doctrine diff so a filter based on `managed-by:doctrine` excludes the table.

When adopting an initial SQL migration for an entire existing database, put the ownership changes
for transferred tables in a later SQL migration so they also run on the existing database.

## Current reconciliation contract

The caller supplies current and target SchemaDefinition values. Stable table and column identities are distinct from SQL names: keeping an identity while changing its name expresses a rename; removing an identity expresses a drop, and adding one expresses creation. SchemaReconciler produces operations, and PostgresSqlGenerator produces SQL statements. StateStore does not currently derive or create its SQL schema automatically.

A column takes a SQL type declaration. Schema resolves it to an immutable `SqlType` for PostgreSQL.
Equivalent spellings of built-in types resolve to the same identity. A change to a SQL modifier, such as
timestamp precision or a varchar length, generates an `ALTER COLUMN TYPE`. Snapshots record the
SQL identity. Fragments that declare the same column must agree on its SQL definition.
The root snapshot layout uses format 3. To move a previously provisioned database to this layout,
start a new history from a baseline that matches the deployed database. If the previous history was
already journaled, configure a separate metadata table for the new history.

See [SchemaReconciler](../../src/Schema/Runtime/SchemaReconciler.php) and [PostgresSqlGenerator](../../src/Schema/Runtime/PostgresSqlGenerator.php).

Tables can declare a primary key by ordered column IDs and foreign keys by constraint name. A foreign key maps
local column IDs to the complete referenced primary key in key order. The reconciler removes obsolete references
before removing tables or columns and adds new references once tables and columns are in place. References
are resolved after composing schema fragments. Changing the primary key of an existing table is unsupported.

Foreign keys default to `ForeignKeyTiming::NotDeferrable`. Use `InitiallyImmediate` for a constraint that a
transaction can defer explicitly, or `InitiallyDeferred` for one checked at commit by default. Changing the
timing of an existing foreign key replaces that constraint during reconciliation.

Foreign keys default to `ForeignKeyAction::NoAction` for `ON DELETE`. Set
`onDelete: ForeignKeyAction::Cascade` in `ForeignKeyDefinition` to delete referencing rows when a referenced
row is deleted. Changing the action replaces the constraint during reconciliation. Snapshots with no
recorded delete action are read as `NoAction`.

Declare named table-level `CHECK` constraints in `TableDefinition::checks`. Values are SQL expressions that
can refer to multiple columns. Fragments can contribute different check names and must agree on the expression
for a shared name. PostgreSQL generation emits the checks at table creation. Changes to checks on existing
tables are rejected during reconciliation.

A `CreateTable` operation generates the table and its declared indexes. The table is created before its indexes,
including structured and raw SQL indexes. Later index additions use `AddIndex`.
On a retained table, the reconciler drops removed or replaced indexes before changing columns, then adds or
renames indexes after the column changes.
The PostgreSQL generator returns an ordered list of individual statements, including management comments.
Structured indexes can declare a PostgreSQL access method with `IndexDefinition::method`.
Use `IndexDefinition::predicate` to supply the SQL `WHERE` expression for a partial index.

A `SchemaDefinition` can declare PostgreSQL extension names in `requiredExtensions` and routines in
`functions`. Schema deduplicates extension requirements and emits `CREATE EXTENSION IF NOT EXISTS` for
new requirements before creating tables and indexes. Manage extension upgrades and removals explicitly.

`PostgresFunctionDefinition` records a stable identity, SQL name, ordered named arguments, return type,
language and body. The generator creates new functions and replaces changed definitions with
`CREATE OR REPLACE FUNCTION` when their signature is unchanged. Signature changes and removals
require an explicit migration.

### Row-level security

Use `TableDefinition::policies` to declare PostgreSQL policies by name on a table. Set
`TableDefinition::rowLevelSecurity` to `RowLevelSecurity::Enabled` to enforce them or to
`RowLevelSecurity::Forced` to also enforce them for the table owner. Use `RowLevelSecurity::Disabled`
to turn off enforcement while keeping declared policies. A fragment that omits the mode leaves the choice
to the other fragments. Explicit modes must agree, and the default for a table is `Disabled`.
Fragments can contribute distinct policies and must agree on the definition of a shared policy name.

```php
new TableDefinition(
    id: 'documents',
    columns: [
        new ColumnDefinition('id', type: ColumnType::Uuid),
        new ColumnDefinition('owner_role', type: ColumnType::Text),
    ],
    primaryKey: ['id'],
    rowLevelSecurity: RowLevelSecurity::Enabled,
    policies: [
        'documents_owner' => new PostgresPolicyDefinition(
            command: PolicyCommand::All,
            roles: ['app_user'],
            using: 'owner_role = current_user::text',
        ),
    ],
);
```

By default a policy is permissive, applies to all commands and targets `PUBLIC`. Supply role names in
`roles` to restrict the audience, and use `PolicyMode::Restrictive` for a restrictive policy. `using` and
`withCheck` are SQL expressions that use physical names. PostgreSQL uses `USING` to filter existing rows
and `WITH CHECK` to validate new rows. For `ALL` and `UPDATE` policies, PostgreSQL uses `USING` for the
write check when `WITH CHECK` is omitted. Enabling RLS before a policy grants access denies access by default
to roles subject to RLS. Create roles and grant table privileges through your deployment tooling.

Snapshots record both the mode and the policies. Snapshots without these fields are read as
RLS disabled with an empty policy list. The reconciler drops removed or changed policies before structural
changes, creates new and replacement policies after tables and functions, and applies the RLS mode last.
Apply migrations that change RLS or policies in a single transaction so sessions see the complete change.
Use a schema migration to create tables with these declarations.

Keep the SQL expressions in the target definition aligned with physical names when renaming objects.
If an otherwise unchanged policy blocks a structural change, such as changing a referenced column's
type, edit the generated migration to drop and recreate the policy around that change.

### SQL types

Use `Ultra\Schema\ColumnType` for built-in types, SQL strings for PostgreSQL declarations, and `Ultra\Schema\SqlType` helpers
for configurable types.

```php
new ColumnDefinition('id', type: ColumnType::Uuid);
new ColumnDefinition('name', type: 'varchar(10)');
new ColumnDefinition('accepted_at', type: SqlType::timestampWithTimeZone(precision: 3));
new ColumnDefinition('metadata', type: 'hstore');
```

Schema can reference types supplied by extensions or the application. Create and alter those types
in explicit SQL migrations. Mapped Table documents the [types it can expose](../stored-state/mapped-table.md#column-types).

## Symfony discovery

`UltraBundle` includes `Ultra\Schema\Symfony\SchemaExtension` when Schema is installed. When assembling a
container directly, register that extension. Use `#[Ultra\Schema\Attribute\DefineSchema]` on a public method of an autoconfigured
class. A bundle can also declare the method directly on a service definition:

```php
$container->register(ProjectionSchema::class)
    ->addTag('ultra.schema.fragment', ['method' => 'projectionSchema']);
```

Each declared method takes no parameters and returns a `SchemaDefinition`. A service can declare multiple
methods. Symfony invokes static methods once during compilation, even when several service definitions
declare the same method. A service such as a projection can declare a static fragment and still be
autowired and injected normally. Static fragments must resolve their foreign keys during compilation
so that compiler passes such as MappedTable can use them.

For instance methods, the compiler passes service factories and method names to the Symfony migration
command. The command calls those methods when it generates a migration and merges their fragments with the
compiled static definition. Instance methods can use service configuration to name their tables and can
reference tables in either set of fragments. Keep their construction and schema methods independent of
database access so generation works offline.

## Guarantee suites

The tests live together under [src/Schema/Tests](../../src/Schema/Tests), organized by observable guarantees:

| Guarantee | Suite |
| --- | --- |
| Fragments combine by identity, preserve explicit names and reject conflicting or duplicate definitions | [DefinitionCompositionTest](../../src/Schema/Tests/DefinitionCompositionTest.php) |
| Comparison preserves index identities and rejects unsupported constraint changes | [SchemaComparisonTest](../../src/Schema/Tests/SchemaComparisonTest.php) |
| Column changes have minimal inspectable payloads; renames preserve stored rows; dependent operations address resolved names; generated SQL applies types, defaults, constraints, indexes and management comments | [ExecutableMigrationTest](../../src/Schema/Tests/ExecutableMigrationTest.php) |
| Composite keys resolve column IDs to physical names, foreign keys enforce the declared timing and delete action, and named table checks enforce rules across columns | [RelationalConstraintsTest](../../src/Schema/Tests/RelationalConstraintsTest.php) |
| Static fragments compose at compilation and tagged service methods contribute to the migration target when the Symfony command runs | [SymfonySchemaDiscoveryTest](../../src/Schema/Tests/SymfonySchemaDiscoveryTest.php) |
| PostgreSQL functions are replaceable at the same signature, with signature changes and removals delegated to explicit migrations | [PostgresFunctionMigrationTest](../../src/Schema/Tests/PostgresFunctionMigrationTest.php) |
| Policies can be deployed before activation, enforce reads and writes for application roles, and can be replaced around structural changes. Forced RLS applies to the owner and disabling RLS preserves policies | [PostgresPolicyMigrationTest](../../src/Schema/Tests/PostgresPolicyMigrationTest.php) |
| Invalid operations fail before a script is returned; caller-provided index SQL retains its clauses and is terminated | [MigrationInspectionTest](../../src/Schema/Tests/MigrationInspectionTest.php) |
| CLI generation requires approval for table and column drops. A shared revision line makes divergent branches conflict in Git, and offline validation rejects incomplete or divergent histories | [MigrationCommandTest](../../src/Schema/Tests/MigrationCommandTest.php) |
| Symfony applies SQL and journals it atomically, verifies applied file checksums, and supports adopting the initial migration on an existing database while replaying it on a fresh database | [PostgresMigrationCommandTest](../../src/Schema/Tests/PostgresMigrationCommandTest.php) |

Operation payloads are inspectable before execution. SQL cases execute the generated statements against PostgreSQL and inspect data and catalog metadata. Whitespace and the layout of generated SQL are not frozen. The raw-index inspection case checks the supplied statement after surrounding whitespace and statement termination are normalized.

The [migration fixture](../../src/Schema/Tests/Support/MigrationTestCase.php) uses PDO PostgreSQL and a unique
database schema in a transaction that it rolls back after each case. The policy suite also creates roles
inside the transaction, so its database user needs `CREATEROLE`. See
[development verification](../development.md#refactoring-tests) for the shared audit workflow.
