# Mapped Table

Adapter-backed row-set unit of work for explicitly buffered row operations.

## Purpose

`Ultra\MappedTable\MappedRowSet` buffers row-level changes and flushes them later through a storage adapter.

The core package owns:

- row-plan buffering
- read-through access that reflects buffered row changes before flush
- row-set reads that reflect buffered row changes before flush
- optimistic expectation merging
- row-set id expectations for detecting inserts and deletes
- deterministic flush ordering
- fluent row update configuration

Adapters own:

- how a storage backend is persisted
- backend-specific concurrency checks

## Core API

```php
use Ultra\MappedTable\InMemoryMappedRowSetAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;

$database = new InMemoryRowDatabase();
$adapter = new InMemoryMappedRowSetAdapter($database, 'users');
$table = new MappedRowSet($adapter);

$table->withId('user-1')
    ->expectColumnValue('status', 'pending')
    ->setColumn('status', 'active');
```

`withId(...)` returns a fluent `PendingRowUpdate` handle for one row in the table.

`row(...)` reads the current row through the adapter and overlays any buffered in-memory mutations for the same id, so callers can observe pending state before `flush()`.

`rows(...)` reads all rows in the logical row set and overlays buffered mutations. `expectSameRowIds(...)` buffers an optimistic expectation that the row id set has not changed before `flush()`.

## Adapter Contract

Adapters implement `Ultra\MappedTable\MappedRowSetAdapter`:

- `row(string|int $id, array $columns = [])` reads one row, optionally projecting columns
- `rows(array $columns = [])` reads all rows in the logical row set keyed by row id
- `apply(RowSetUpdatePlan $plan)` receives optional row-set id expectations and the sorted batch of immutable row plans

`MappedRowSet` itself does not open transactions. If you need atomicity across a flush, open the surrounding transaction on the underlying storage before calling `flush()`.

## Included In-Memory Adapter

`Ultra\MappedTable\InMemoryMappedRowSetAdapter` provides a lightweight adapter for tests and local execution.

It delegates storage to `Ultra\MappedTable\InMemoryRowDatabase`, which exposes a DBAL-like transaction API:

- `beginTransaction()`
- `commit()`
- `rollBack()`
- `isTransactionActive()`

Writes are applied directly to the in-memory tables, `commit()` simply closes the transaction, and `rollBack()` restores the snapshot captured at `beginTransaction()`.

## PostgreSQL Adapter

When `doctrine/dbal` is installed, you can use `Ultra\MappedTable\PostgresMappedRowSetAdapter`:

```php
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;

$table = new MappedRowSet(new PostgresMappedRowSetAdapter($connection, 'users'));
```

The Postgres adapter checks expected column values with `IS NOT DISTINCT FROM`, so `null` expectations are handled correctly.
Row-set id expectations use a transaction-scoped advisory lock and therefore require an active transaction.
