# Schema


Schema is an incomplete building block for schema definition and reconciliation. Its intended direction includes automating schema creation in a role comparable to Doctrine's schema tooling. How far Ultra should infer schema from other building blocks remains open. Application developers must retain the choice to define their own tables and constraints.

This intention does not imply that automatic schema derivation is currently complete.

Schema currently separates reconciliation operations from Postgres SQL generation. Future automation should preserve inspection before execution, including for destructive operations. Exporting generated SQL to a script file, similar to Doctrine workflows, is a likely extension rather than a promised current command.

## Current reconciliation contract

The caller supplies current and target SchemaDefinition values. Stable table and column identities are distinct from SQL names: keeping an identity while changing its name expresses a rename; removing an identity expresses a drop, and adding one expresses creation. SchemaReconciler produces operations, and PostgresSqlGenerator produces SQL statements. StateStore does not currently derive or create its SQL schema automatically.

See [SchemaReconciler](../../src/Schema/SchemaReconciler.php) and [PostgresSqlGenerator](../../src/Schema/PostgresSqlGenerator.php).
