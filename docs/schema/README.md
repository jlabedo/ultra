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

`ColumnType::Text` and `ColumnType::Int32` are portable column definitions with a PostgreSQL translation.
Column definitions also accept SQL type strings and SQL constraints for dialect-specific declarations.
[MappedTable](../stored-state/mapped-table.md#compile-row-sets-from-schema) can compile row sets from these
same table definitions.

## Guarantee suites

The tests live together under [src/Schema/Runtime/Tests](../../src/Schema/Tests), organized by observable guarantees:

| Guarantee | Suite |
| --- | --- |
| Fragments combine by identity, preserve explicit names and reject conflicting or duplicate definitions | [DefinitionCompositionTest](../../src/Schema/Tests/DefinitionCompositionTest.php) |
| Comparison preserves index identities and rejects unsupported constraint changes | [SchemaComparisonTest](../../src/Schema/Tests/SchemaComparisonTest.php) |
| Column changes have minimal inspectable payloads; renames preserve stored rows; dependent operations address resolved names; generated SQL applies types, defaults, constraints, indexes and management comments | [ExecutableMigrationTest](../../src/Schema/Tests/ExecutableMigrationTest.php) |
| Composite keys resolve column IDs to physical names, enforce references and allow related tables to be dropped | [RelationalConstraintsTest](../../src/Schema/Tests/RelationalConstraintsTest.php) |
| Invalid operations fail before a script is returned; caller-provided index SQL retains its clauses and is terminated | [MigrationInspectionTest](../../src/Schema/Tests/MigrationInspectionTest.php) |

Operation payloads are inspectable before execution. SQL cases execute the generated statements against PostgreSQL and inspect data and catalog metadata. Whitespace and the layout of generated SQL are not frozen. The raw-index inspection case checks the supplied statement after surrounding whitespace and statement termination are normalized.

The [migration fixture](../../src/Schema/Tests/Support/MigrationTestCase.php) uses a unique database schema in a transaction and rolls it back after each case. It uses PDO PostgreSQL to consume operations containing several statements, such as DDL followed by comments. See [development verification](../development.md#refactoring-tests) for the shared audit workflow.

## Current limitations

New-table reconciliation currently emits a CreateTable operation whose SQL omits declared indexes; callers must supply AddIndex operations separately. Removing a column and its dependent index currently places the column drop first, after which PostgreSQL has already removed that index and the later index drop fails. These are incomplete reconciliation paths, with follow-up tracked in [TODO](../../TODO.md); the executable guarantee cases do not promise those migrations work.
