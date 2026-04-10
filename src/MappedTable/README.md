# Mapped Table

Adapter-backed row-mutation unit of work for explicitly buffered table operations.

## Purpose

`Ultra\MappedTable\MappedTable` buffers row-level changes and flushes them later through a storage adapter.

The core package owns:

- row-plan buffering
- read-through access that reflects buffered row changes before flush
- optimistic expectation merging
- deterministic flush ordering
- fluent row update configuration

Adapters own:

- how a storage backend is persisted
- backend-specific concurrency checks

## Core API

```php
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedTable;

$database = new InMemoryRowDatabase();
$adapter = new InMemoryMappedTableAdapter($database);
$table = new MappedTable($adapter, 'users');

$table->withId('user-1')
    ->expectColumnValue('status', 'pending')
    ->setColumn('status', 'active');
```

`withId(...)` returns a fluent `PendingRowUpdate` handle for one row in the table.

`row(...)` reads the current row through the adapter and overlays any buffered in-memory mutations for the same id, so callers can observe pending state before `flush()`.

## Adapter Contract

Adapters implement `Ultra\MappedTable\MappedTableAdapter`:

- `row(string $tableName, string $idColumn, string|int $id, array $columns = [])` reads one row, optionally projecting columns while always including the id column when found
- `apply(string $tableName, string $idColumn, array $rowUpdates)` receives the target table metadata and full sorted batch of immutable row plans

`MappedTable` itself does not open transactions. If you need atomicity across a flush, open the surrounding transaction on the underlying storage before calling `flush()`.

## Included In-Memory Adapter

`Ultra\MappedTable\InMemoryMappedTableAdapter` provides a lightweight adapter for tests and local execution.

It delegates storage to `Ultra\MappedTable\InMemoryRowDatabase`, which exposes a DBAL-like transaction API:

- `beginTransaction()`
- `commit()`
- `rollBack()`
- `isTransactionActive()`

Writes are applied directly to the in-memory tables, `commit()` simply closes the transaction, and `rollBack()` restores the snapshot captured at `beginTransaction()`.

## PostgreSQL Adapter

When `doctrine/dbal` is installed, you can use `Ultra\MappedTable\PostgresMappedTableAdapter`:

```php
use Ultra\MappedTable\MappedTable;
use Ultra\MappedTable\PostgresMappedTableAdapter;

$table = new MappedTable(new PostgresMappedTableAdapter($connection), 'users');
```

The Postgres adapter checks expected column values with `IS NOT DISTINCT FROM`, so `null` expectations are handled correctly.
