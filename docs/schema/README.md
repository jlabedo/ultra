# Schema

Schema is an incomplete building block for schema definition and reconciliation. Its intended direction includes automating schema creation in a role comparable to Doctrine's schema tooling. How far Ultra should infer schema from other building blocks remains open. Application developers must retain the choice to define their own tables and constraints.

This intention does not imply that automatic schema derivation is currently complete.

Schema currently separates reconciliation operations from Postgres SQL generation. Future automation should preserve inspection before execution, including for destructive operations. Exporting generated SQL to a script file, similar to Doctrine workflows, is a likely extension rather than a promised current command.

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

A `CreateTable` operation generates the table and its declared indexes. The table is created before its indexes,
including structured and raw SQL indexes. Later index additions use `AddIndex`.
The PostgreSQL generator returns an ordered list of individual statements, including management comments.

`ColumnType::Text`, `Int32`, `Int64` and `Boolean` are portable column types with a PostgreSQL translation.
Column definitions also accept SQL type strings and SQL constraints for dialect-specific declarations.
[MappedTable](../stored-state/mapped-table.md#compile-row-sets-from-schema) can compile row sets from these
same table definitions.

## Symfony discovery

`UltraBundle` includes `Ultra\Schema\Symfony\SchemaExtension` when Schema is installed. When assembling a
container directly, register that extension. On an autoconfigured resource, mark a public static method
with `#[Ultra\Schema\Attribute\DefineSchema]`. The method takes no parameters and declares
`SchemaDefinition` as its return type. A method may provide any number of tables.

Symfony invokes each discovered method once during compilation. It merges the fragments by table and
column ID and validates foreign keys across the assembled schema. Compiler passes such as MappedTable
consume the result during compilation. The fragment providers and the assembled schema are discarded
from the dumped container. For DDL generation outside Symfony, the same methods can be called directly.

## Guarantee suites

The tests live together under [src/Schema/Runtime/Tests](../../src/Schema/Tests), organized by observable guarantees:

| Guarantee | Suite |
| --- | --- |
| Fragments combine by identity, preserve explicit names and reject conflicting or duplicate definitions | [DefinitionCompositionTest](../../src/Schema/Tests/DefinitionCompositionTest.php) |
| Comparison preserves index identities and rejects unsupported constraint changes | [SchemaComparisonTest](../../src/Schema/Tests/SchemaComparisonTest.php) |
| Column changes have minimal inspectable payloads; renames preserve stored rows; dependent operations address resolved names; generated SQL applies types, defaults, constraints, indexes and management comments | [ExecutableMigrationTest](../../src/Schema/Tests/ExecutableMigrationTest.php) |
| Composite keys resolve column IDs to physical names, enforce references with the declared timing and allow related tables to be dropped | [RelationalConstraintsTest](../../src/Schema/Tests/RelationalConstraintsTest.php) |
| Autoconfigured fragments compose at compilation, including cross-fragment references | [SymfonySchemaDiscoveryTest](../../src/Schema/Tests/SymfonySchemaDiscoveryTest.php) |
| Invalid operations fail before a script is returned; caller-provided index SQL retains its clauses and is terminated | [MigrationInspectionTest](../../src/Schema/Tests/MigrationInspectionTest.php) |

Operation payloads are inspectable before execution. SQL cases execute the generated statements against PostgreSQL and inspect data and catalog metadata. Whitespace and the layout of generated SQL are not frozen. The raw-index inspection case checks the supplied statement after surrounding whitespace and statement termination are normalized.

The [migration fixture](../../src/Schema/Tests/Support/MigrationTestCase.php) uses PDO PostgreSQL and a unique
database schema in a transaction that it rolls back after each case. See
[development verification](../development.md#refactoring-tests) for the shared audit workflow.

## Current limitations

Removing a column and its dependent index currently places the column drop first. PostgreSQL has already
removed the index when the later index drop runs, so that operation fails. See [TODO](../../TODO.md).
