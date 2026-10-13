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

`withKey($key)->delete()` buffers a conditional deletion, retaining existing column and presence expectations.
Without a prior absence expectation, the row must still exist at flush. Deleting an expected-absent row only
checks absence; a buffered insertion for that key is cancelled, and a competing creation still causes a conflict.

`row(...)` reads the current row through the adapter and overlays any buffered in-memory mutations for the same id, so callers can observe pending state before `flush()`.

`rows(...)` returns a list of `MappedRow` objects containing a structured `MappedKey` and the projected columns. It overlays buffered mutations. `expectSameRowKeys(...)` buffers an optimistic expectation that the row-key set has not changed before `flush()`.

`reconcileRows(iterable $rows)` accepts desired `MappedRow` values, upserts their supplied columns and deletes
rows missing from that set within the adapter's scope at flush. Preparing reconciliation does not read storage.
The desired membership is reflected immediately in `row()` and `rows()`, alongside buffered column changes.
An empty set clears the scope; successive calls supersede earlier membership and discard writes to removed rows.
Unspecified columns on retained rows and existing optimistic expectations are preserved. Repeated keys merge their supplied columns in input order, with the last supplied
value winning. Reconciliation does not register new optimistic expectations. Without prior expectations, it
imposes the saved membership, removing rows added to storage before the flush deletion. An earlier
`expectSameRowKeys()` still rejects concurrent membership changes. A key expectation registered after
reconciliation checks the local membership without replacing any earlier committed expectation. Later explicit
row writes can add or remove keys from the reconciled set. `clear()` discards the reconciliation.

### Expectations and the unit-of-work view

`row(...)` and `rows(...)` return the unit of work's view: committed data with buffered changes applied. Expectations are written against that same view:

- a value, presence or row key decided by a buffered change is checked immediately, and a mismatch throws `ConcurrencyException` right away;
- the rest comes from storage and becomes a condition checked by the adapter at flush.

So a caller can read a row, expect what it read and write it, even when an earlier operation in the same unit of work already changed that row. A stale copy read before that change is rejected. Order matters: `expectColumnValue('status', 'pending')->setColumn('status', 'active')` guards the committed value, while expecting `status` after setting it checks the buffered value.

Row expectations are checked at flush even when the row is not written: `MappedRowSet` then flushes a check-only plan (`RowUpdatePlan::MODE_EXPECT`, `writes()` is false) that the adapter checks without writing. Such rows count as pending changes. An update that sets every column to the value it expects is also flushed as a check-only plan, so saving unchanged rows writes nothing.

`withKey($key)->expectPresent()` checks presence without comparing columns. Buffered writes satisfy it locally;
otherwise the committed row must exist at flush. A buffered deletion or a conflicting absence expectation is
rejected immediately. This also guards states whose mapping contains only their identifier.

`expectSameRowKeys(...)` keeps its first committed expectation. Keys whose presence was decided by buffered changes when an expectation was registered are ignored by the adapter's comparison (`RowSetUpdatePlan::$ignoredRowKeys`); their own row plans guard them.

## Adapter Contract

Adapters implement `Ultra\MappedTable\MappedRowSetAdapter`:

- `keyColumns()` supplies the complete physical row key
- `scope()` supplies any bound key columns that limit this row-set view
- `row(MappedKey $key, array $columns = [])` reads one row, optionally projecting columns
- `rows(array $columns = [])` reads all rows as `MappedRow` values
- `apply(RowSetUpdatePlan $plan)` receives optional row-set key expectations (plus the keys to leave out of that comparison) the sorted batch of immutable row plans and optional retained row keys; it checks the key expectations and the check-only plans against committed rows before applying the writes, then removes rows outside the retained set within its scope

`MappedRowSet` itself does not open transactions. If you need atomicity across a flush, open the surrounding transaction on the underlying storage before calling `flush()`.

### Table policy

`MappedTablePolicy` defines the operations a table supports, independently of its storage backend.
Both included adapters accept a `policy`; their factories accept `tablePolicies`, keyed by table name,
and apply each policy to both `create()` and `createScoped()`. Unspecified tables use the default policy,
which allows check-only absence observations.

```php
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\MappedTablePolicy;
use Ultra\MappedTable\PostgresMappedRowSetAdapterFactory;

$tablePolicies = [
    'course_names' => new MappedTablePolicy(allowAbsentObservations: false),
];
$postgresFactory = new PostgresMappedRowSetAdapterFactory($connection, tablePolicies: $tablePolicies);
$memoryFactory = new InMemoryMappedRowSetAdapterFactory($database, tablePolicies: $tablePolicies);
```

Only the adapters receive the policy. `MappedRowSet` buffers expectations and mutations, and `updatePlan()`
remains an inspectable snapshot without lock instructions or policy validation. At `apply()`, both adapters
validate the final plan through the policy before checking storage or applying writes. When absence observations
are disabled, a check-only absence plan throws `LogicException`. `expectAbsent()->insert()` remains allowed;
`expectAbsent()->insert()->delete()` becomes a check-only absence plan and is rejected. Contradictions with
buffered operations or expectations can still fail immediately when registered.

All adapters accessing the same physical table must use the same policy, including scoped views and other
processes. An adapter's concurrency protocol relies on every writer participating when absence observations
are allowed. The policy is configured by the application; factories do not coordinate independent configurations.
Each backend owns the mechanisms needed to enforce the permitted expectations and writes.

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

When the table policy disables [absence observations](#table-policy), inserts and upserts skip these per-key
advisory locks. SQL constraints, affected-row checks, present-row locks, row-set expectations and caller-owned
transactions remain in force.

`MappedRowSet` buffers row mutations and optimistic expectations. `updatePlan()` exposes an immutable snapshot
for inspection; `flush()` applies its own plan through the adapter. Cooperative decision guards and query replay
belong to `StateStore`'s coordinating unit of work and its dedicated guard adapter. They are acquired before the
mapped row sets are flushed. The persistence locks described above stay local to each mapped adapter.

### Column types

Pass `PostgresMappedColumnType` instances by physical column name to `PostgresMappedRowSetAdapter`, or by
table and column name in `PostgresMappedRowSetAdapterFactory::columnTypesByTable`. The default is text;
configured types provide encoding, decoding and SQL casts for single writes, bulk writes and optimistic comparisons.

`timestamp()` targets `TIMESTAMP(6) WITHOUT TIME ZONE`: it preserves wall-clock values and returns them
as RFC 3339 strings with a UTC offset. `timestampWithTimeZone()` targets `TIMESTAMP(6) WITH TIME ZONE`:
it preserves instants and microseconds, while returned offsets follow the database session's timezone.
Both accept date strings, `DateTimeInterface` objects and null. PostgreSQL validates string timestamp values;
its timezone-aware storage does not retain the original textual offset or named timezone.

```php
$factory = new PostgresMappedRowSetAdapterFactory($connection, [
    'acceptances' => ['accepted_at' => PostgresMappedColumnType::timestampWithTimeZone()],
]);
```

Other available types include `text()`, `date()`, `jsonb()`, `textArray()`, `uuidArray()` and `intArray()`.

## SQL inspection

The [standalone SQL inspection example](../../examples/mapped-table-sql/README.md) builds a `MappedRowSet`,
captures its pending plan and traces the real PostgreSQL execution through an opt-in DBAL middleware. It
includes scenarios for grouped writes, check-only observations and optimistic conflicts, with isolated
temporary data and automatic cleanup. The example owns setup, authoring and report instructions; the storage
and transaction contracts remain unchanged.

## Test ownership and maintenance

Each suite owns one guarantee and its cases. Tests are not grouped by implementation class or physical table.
The shared fixture sets up storage, performs independent committed reads and cleans up transactions; it contains
no assertions or model of the expected behavior. Common persistence cases use a backend data provider so the
same scenario exercises the in-memory and Postgres adapters, including their factories.

| Guarantee | Suite | Cases |
| --- | --- | --- |
| Reads expose the unit-of-work view without changing storage | [BufferedViewTest](../../src/StoredState/Tests/MappedTable/BufferedViewTest.php) | Committed projections and structured rows, overlays of updates/inserts/deletes, check-only observations. |
| Writes have distinct insert, update, upsert, replacement and deletion semantics | [WriteSemanticsTest](../../src/StoredState/Tests/MappedTable/WriteSemanticsTest.php) | Preservation of unspecified values, conflicts, multiple deletions, replacement expectations, cancellation and merging. |
| Optimistic expectations protect the values, presence and membership a decision observes | [OptimisticConcurrencyTest](../../src/StoredState/Tests/MappedTable/OptimisticConcurrencyTest.php) | Local versus committed expectations, immediate conflicts, check-only flushes, final-plan table policies, concurrent insertions/deletions and first-expectation retention. |
| Saved membership supersedes earlier membership within its scope | [MembershipReconciliationTest](../../src/StoredState/Tests/MappedTable/MembershipReconciliationTest.php) | Late rows, empty and successive sets, duplicate keys, retained columns, earlier expectations and later explicit operations. |
| Preparation, flush, clear and transactions retain their separate responsibilities | [FlushLifecycleTest](../../src/StoredState/Tests/MappedTable/FlushLifecycleTest.php) | Preparation without I/O, sorted immutable snapshots, check-only writes, complete cleanup, external rollback and in-memory transaction snapshots. |
| Keys and scopes address independent rows | [KeyAndScopeIsolationTest](../../src/StoredState/Tests/MappedTable/KeyAndScopeIsolationTest.php) | Composite keys, scalar shortcuts, colliding IDs, multiple scope columns and invalid addresses. |
| Persisted column values survive encoding and decoding | [ColumnValueRoundTripTest](../../src/StoredState/Tests/MappedTable/ColumnValueRoundTripTest.php) | Text/UUID/integer arrays, escaping and nulls, JSON, dates, booleans, single and multiple writes and optimistic typed comparisons. |
| Persistence observations hold competing writers until their transaction ends | [PersistenceLockingTest](../../src/StoredState/Tests/MappedTable/PersistenceLockingTest.php) | Two real Postgres connections, present/absent checks, insert/upsert/update contention, release, transaction requirements and table-wide absence protection. |
| Standalone inspection preserves pending plans and distinguishes actual SQL, failures and fixture work | [SqlInspectionTest](../../src/StoredState/Tests/MappedTable/SqlInspectionTest.php) | Merged batches, reads before transactions, checks without writes, executed prefixes, rollback, setup/work failures, cleanup, repeated runs and CLI status. |

Add or change a case in the suite owning its guarantee. A new file needs a distinct guarantee, rather than a
new implementation class. Use another dataset for a different input or failure mode of the same guarantee;
do not duplicate a scenario for each adapter. Buffer-only rules use the in-memory fixture. Backend behavior
such as SQL types, unchanged row versions and locking requires Postgres cases.

Use [development verification](../development.md#tests) for setup and execution, and the
[refactoring checks](../development.md#refactoring-tests) before removing or adding cases. Coverage metadata
changes are not evidence of newly exercised behavior; coverage and mutation scores complement the contract review.
