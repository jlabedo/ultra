# Persist your first state

This journey creates a PostgreSQL-backed state, changes it through Symfony's container, commits it with the unit-of-work runner and reads the committed value. Follow the [getting-started application setup](../../examples/getting-started/README.md#setup), then run commands from `examples/getting-started`.

## Define the state and its table

The [Contact class](../../examples/getting-started/src/Domain/Contact.php) declares `#[State(table: 'tutorial_contacts')]`, a string identifier mapped by `#[StateId(column: 'id')]`, and a name mapped by `#[StateColumn('name')]`.

The [setup script](../../examples/getting-started/bin/setup.php) creates its table:

```sql
CREATE TABLE tutorial_contacts (id TEXT PRIMARY KEY, name TEXT NOT NULL)
```

StateStore does not derive this DDL. Own the schema and migrations in the application. For embedded values and collections, continue with the [mapping contract](mapping.md).

## Assemble and execute

The application's [Kernel](../../examples/getting-started/src/Kernel.php) enables Mapper, StateStore and UnitOfWork. Its [services](../../examples/getting-started/config/services.yaml) register the state as an autoconfigured resource and supply a PostgreSQL mapped adapter factory plus `DoctrineTransactionManager`, both using the same connection. See [Symfony assembly](../symfony.md) for adapting this to another application.

The [RenameContact command](../../examples/getting-started/src/Command/RenameContact.php) receives `StateStore` and `UnitOfWorkRunner`. Inside `run()`, it calls `observe()` and then saves the replacement state. Observation protects the old values, or the row's absence for a new contact. `save()` prepares the write; the runner flushes it in the configured transaction and clears buffered work. The command then uses a new runner boundary to load and verify the committed value.

```bash
php bin/console app:contact:rename ada Ada
php bin/console app:contact:rename ada 'Ada Lovelace'
```

Expected output is `ada: Ada`, then `ada: Ada Lovelace`. The second command starts another process and updates the existing row. The command checks its read-back result; values remain in PostgreSQL after exit.

## Handle conflicts and choose read semantics

Use `load()` for a read that needs no optimistic write expectation. Use `observe()` for a decision that depends on the existing values or absence. Catch `Ultra\UnitOfWork\Exception\ConcurrencyException` at the application boundary if the decision conflicts at flush. Repeating a command means making a new decision from newly observed state; choose that retry policy in the application.

Read the [StateStore contract](README.md#reads-and-observations) for observation variants and the [transaction contract](../transactions/README.md#unitofworkrunner) for nested work and cleanup. For set-based invariants beyond one row, follow [query guards](query-guards.md). For a complete composition without Symfony, see [IAM](../../examples/iam/README.md).
