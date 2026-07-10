# Mapped Table

Adapter-backed row-set unit of work for explicitly buffered row operations.

## Purpose

`Ultra\MappedTable\MappedRowSet` buffers row-level changes and flushes them later through a storage adapter.

The core package owns:

- row-plan buffering
- read-through access that reflects buffered row changes before flush
- row-set reads that reflect buffered row changes before flush
- optimistic expectation merging
- row-set key expectations for detecting inserts and deletes
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

$table->withKey('user-1')
    ->expectColumnValue('status', 'pending')
    ->setColumn('status', 'active');
```

`withKey(...)` returns a fluent `PendingRowUpdate` handle for one row in the table. A scalar is accepted when exactly one key column remains unbound. Composite rows use `MappedKey`:

```php
use Ultra\MappedTable\MappedKey;

$key = new MappedKey([
    'identity_id' => 'identity-1',
    'terms_id' => 'terms-2026',
]);

$table->withKey($key)->insert();
```

`row(...)` reads the current row through the adapter and overlays any buffered in-memory mutations for the same id, so callers can observe pending state before `flush()`.

`rows(...)` returns a list of `MappedRow` objects containing a structured `MappedKey` and the projected columns. It overlays buffered mutations. `expectSameRowKeys(...)` buffers an optimistic expectation that the row-key set has not changed before `flush()`.

## Adapter Contract

Adapters implement `Ultra\MappedTable\MappedRowSetAdapter`:

- `row(MappedKey $key, array $columns = [])` reads one row, optionally projecting columns
- `rows(array $columns = [])` reads all rows as `MappedRow` values
- `apply(RowSetUpdatePlan $plan)` receives optional row-set key expectations and the sorted batch of immutable row plans

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
Row-set key expectations use a transaction-scoped advisory lock and therefore require an active transaction.

## Owned Child Sets

`MappedRowSet`'s invariant is "row identity = primary key." When a parent key
owns a variable-length set of detail rows whose row-level identity is just an
implementation detail (e.g. intervals per `(subject_id, capability_id)`,
invoice line items per invoice, tags per entity), the only meaningful write
is "replace the whole owned set."

`Ultra\MappedTable\MappedOwnedRowSet` is the sibling primitive for that
pattern. One operation:

```php
use Ultra\MappedTable\MappedOwnedRowSet;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\PostgresMappedOwnedRowSetAdapter;

$set = new MappedOwnedRowSet(new PostgresMappedOwnedRowSetAdapter(
    $connection,
    tableName: 'eligibility_intervals',
    parentColumns: ['subject_id', 'capability_id'],
    rowColumns: ['eligible_from', 'eligible_to'],
));

$set->withParent(new MappedKey(['subject_id' => 's-1', 'capability_id' => 'c-1']))
    ->replace([
        ['eligible_from' => '2026-01-01', 'eligible_to' => '2026-06-01'],
        ['eligible_from' => '2026-07-01', 'eligible_to' => null],
    ]);

$set->flush();
```

`replace(...)` buffers a `DELETE WHERE parent = ?` + bulk `INSERT` that flushes
with the unit of work alongside any sibling `MappedRowSet` instances. Calling
`replace(...)` again for the same parent overwrites the previously buffered
set; replacing with `[]` clears the owned set. `rowsForParent(...)` reads the
adapter and overlays a buffered replacement, mirroring `MappedRowSet::row()`.
