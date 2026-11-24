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
arguments and options. Generation starts from an empty schema when the history is empty. For an existing
database, first pass a definition that describes its current Ultra-managed tables:

```bash
vendor/bin/ultra-schema init config/schema.php migrations/schema
```

`init` records `000000-baseline/snapshot.json` in an empty history. Verify the baseline against the database
before generating further changes.

### Review and deploy

Each generation compares the target with the latest versioned snapshot, including when the corresponding
SQL is still pending deployment. It writes `migration.sql` and a versioned JSON `snapshot.json` together in a
numbered subdirectory such as `000001-create-tables`. Use lowercase letters and digits separated by hyphens
for the migration name. The snapshot records the predecessor and retains the table, column and index identities
used to recognize renames. A diff with no operations creates no entry.

For table or column drops, the command lists the affected objects and exits before writing an entry.
Review the target definition and rerun with `--allow-destructive` to generate the SQL if the drops are intended.

Review and commit both files together. If review changes the resulting structure of a declared table,
keep the snapshot and target definition aligned with the revised SQL. Apply the forward SQL in numbered
order through the deployment tooling, which also tracks applied migrations and handles any rollback.
An incomplete entry or divergent history stops generation. If branches generated migrations from the
same predecessor, choose an order and regenerate the later migration from the new history, carrying
over any reviewed SQL edits.

### Coexist with Doctrine

Schema reconciles the objects declared in its snapshots and supplied definition. An application can keep
separate Schema and Doctrine histories on the same PostgreSQL database. Assign each table to one
generator and configure Doctrine's schema introspection to exclude tables outside its scope. An
application can use its own naming or ownership convention. Add foreign keys across the two scopes
in a hand-written migration and account for their dependencies when ordering deployment.

## Current reconciliation contract

The caller supplies current and target SchemaDefinition values. Stable table and column identities are distinct from SQL names: keeping an identity while changing its name expresses a rename; removing an identity expresses a drop, and adding one expresses creation. SchemaReconciler produces operations, and PostgresSqlGenerator produces SQL statements. StateStore does not currently derive or create its SQL schema automatically.

See [SchemaReconciler](../../src/Schema/Runtime/SchemaReconciler.php) and [PostgresSqlGenerator](../../src/Schema/Runtime/PostgresSqlGenerator.php).

Tables can declare a primary key by ordered column IDs and foreign keys by constraint name. A foreign key maps
local column IDs to the complete referenced primary key in key order. The reconciler removes obsolete references
before removing tables or columns and adds new references once tables and columns are in place. References
are resolved after composing schema fragments. Changing the primary key of an existing table is unsupported.

Foreign keys default to `ForeignKeyTiming::NotDeferrable`. Use `InitiallyImmediate` for a constraint that a
transaction can defer explicitly, or `InitiallyDeferred` for one checked at commit by default. Changing the
timing of an existing foreign key replaces that constraint during reconciliation.

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
require an explicit migration. Existing table-only snapshots can still be read.

`ColumnType::Text`, `Int32`, `Int64` and `Boolean` are portable column types with a PostgreSQL translation.
Column definitions also accept SQL type strings and SQL constraints for dialect-specific declarations.
[MappedTable](../stored-state/mapped-table.md#compile-row-sets-from-schema) can compile row sets from these
same table definitions.

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
declare the same method. Static fragments must resolve their foreign keys during compilation so that
compiler passes such as MappedTable can use them.

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
| Composite keys resolve column IDs to physical names, foreign keys enforce references with the declared timing and named table checks enforce rules across columns | [RelationalConstraintsTest](../../src/Schema/Tests/RelationalConstraintsTest.php) |
| Static fragments compose at compilation and tagged service methods contribute to the migration target when the Symfony command runs | [SymfonySchemaDiscoveryTest](../../src/Schema/Tests/SymfonySchemaDiscoveryTest.php) |
| PostgreSQL functions are replaceable at the same signature, with signature changes and removals delegated to explicit migrations | [PostgresFunctionMigrationTest](../../src/Schema/Tests/PostgresFunctionMigrationTest.php) |
| Invalid operations fail before a script is returned; caller-provided index SQL retains its clauses and is terminated | [MigrationInspectionTest](../../src/Schema/Tests/MigrationInspectionTest.php) |
| CLI generation uses the versioned history, requires approval for table and column drops, and rejects incomplete or divergent histories | [MigrationCommandTest](../../src/Schema/Tests/MigrationCommandTest.php) |

Operation payloads are inspectable before execution. SQL cases execute the generated statements against PostgreSQL and inspect data and catalog metadata. Whitespace and the layout of generated SQL are not frozen. The raw-index inspection case checks the supplied statement after surrounding whitespace and statement termination are normalized.

The [migration fixture](../../src/Schema/Tests/Support/MigrationTestCase.php) uses PDO PostgreSQL and a unique
database schema in a transaction that it rolls back after each case. See
[development verification](../development.md#refactoring-tests) for the shared audit workflow.
