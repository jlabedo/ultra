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

### Expectations and the unit-of-work view

`row(...)` and `rows(...)` return the unit of work's view: committed data with buffered changes applied. Expectations are written against that same view:

- a value, presence or row key decided by a buffered change is checked immediately, and a mismatch throws `ConcurrencyException` right away;
- the rest comes from storage and becomes a condition checked by the adapter at flush.

So a caller can read a row, expect what it read and write it, even when an earlier operation in the same unit of work already changed that row. A stale copy read before that change is rejected. Order matters: `expectColumnValue('status', 'pending')->setColumn('status', 'active')` guards the committed value, while expecting `status` after setting it checks the buffered value.

Row expectations are checked at flush even when the row is not written: `MappedRowSet` then flushes a check-only plan (`RowUpdatePlan::MODE_EXPECT`, `writes()` is false) that the adapter checks without writing. Such rows count as pending changes. An update that sets every column to the value it expects is also flushed as a check-only plan, so saving unchanged rows writes nothing.

`expectSameRowKeys(...)` keeps its first committed expectation. Keys whose presence was decided by buffered changes when an expectation was registered are ignored by the adapter's comparison (`RowSetUpdatePlan::$ignoredRowKeys`); their own row plans guard them.

## Adapter Contract

Adapters implement `Ultra\MappedTable\MappedRowSetAdapter`:

- `tableName()` supplies the physical table identity used for ordering manual guards
- `lock(MappedKey $key)` acquires a cooperative transaction-scoped guard, even for a missing row
- `row(MappedKey $key, array $columns = [])` reads one row, optionally projecting columns
- `rows(array $columns = [])` reads all rows as `MappedRow` values
- `apply(RowSetUpdatePlan $plan)` receives optional row-set key expectations (plus the keys to leave out of that comparison) and the sorted batch of immutable row plans; it checks the key expectations and the check-only plans against committed rows before applying the writes

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

Check-only rows are checked in one `SELECT ... FOR SHARE` per table, so they stay locked against concurrent writers until the transaction ends. An absent row cannot be locked, so rows expected absent, and rows an insert or upsert may create, take a transaction-scoped advisory lock on their key (one statement, keys in sorted order): an absent check holds back a concurrent creation of the row until its transaction ends, and the other way around. Absent checks require an active transaction; an insert outside a transaction takes no lock.

### Deferred manual guards

`$table->lock($key)` buffers a manual guard without executing SQL. `flush()` acquires the guards before applying
that row set's plan. For several row sets, use `MappedRowSet::flushAll($tables, $validate)` in a single surrounding
transaction: it deduplicates and sorts all guards by physical table and key, runs the optional read-only validation
callback, then applies the plans in the supplied table order. This also handles overlapping root and scoped views
of the same table. Both standalone `flush()` and `flushAll()` require an active transaction when manual guards exist.
`clear()` discards guards along with buffered plans.

Postgres manual guards use transaction-scoped advisory locks under the `:manual` namespace, separate from the
automatic absent-row (`:row`) and row-set expectation namespaces. They only serialize cooperating callers; they
do not lock a database row against arbitrary SQL writers. The in-memory adapter validates the key and transaction
but does not simulate competing connections. `StateTableRegistry` uses `flushAll()` to protect deferred query
expectations without acquiring every write plan's row locks in advance.

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
