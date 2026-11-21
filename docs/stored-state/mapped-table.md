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

## Defined row sets and owner collections

A `MappedRowSetDefinition` declares the physical table, its complete key and the columns exposed to callers.
Each `MappedColumn` declares the PHP representation after decoding and whether it accepts null. The supported
representations are `int`, `string`, `bool`, `float` and normalized arrays of scalar values, null and nested
arrays. A key column is a non-null integer or string with a single declared representation. Row writes assign
only non-key columns. The database still owns defaults and constraints. A value supplied by a database default
becomes available after persistence.

```php
final class OrderLinesDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return new MappedRowSetMapping('order_lines', [
            'order_id' => new MappedColumn('string'),
            'line_id' => new MappedColumn('string'),
            'sku' => new MappedColumn('string'),
        ], ['order_id', 'line_id'], ownerColumns: ['order_id']);
    }
}
```

With Symfony, add `Ultra\MappedTable\Symfony\MappedTableExtension`, register definitions as autoconfigured
resources and bind `MappedRowSetAdapterFactory` to the application's factory and connection. A definition is
instantiated without constructor parameters at compilation. Its class name identifies the shared
`MappedRowSet` service, which can be injected using `#[Autowire(service: OrderLinesDefinition::class)]` on a
`MappedRowSet` parameter. The extension allows one definition per physical table. It compiles key and owner
columns, their lookup sets and the default read projections. The dumped container uses those validated values.

With `PostgresMappedRowSetAdapterFactory` selected, `PostgresMappedRowSetConfiguration` supplies codecs for the
definition's columns and takes precedence over factory defaults. Symfony resolves defaults declared using the
built-in codec factories, checks codec compatibility with the declared PHP representation and prepares the
static SQL fragments for the table, columns, keys and owner. Defaults supplied by a factory instance at runtime
are resolved when that factory creates the adapter. For standalone assembly, pass the mapping and an adapter
for its complete key to `MappedRowSet`.

The owner columns, when present, identify one collection for each complete owner key in the child table's
column names. They leave at least one further key column for the item. Table reads, reads by owner and direct
writes by complete row key all use the same buffer.

```php
$owner = ['order_id' => 'o-42'];
$keys = $lines->observeKeysForOwner($owner);
$lines->reconcileForOwner($owner, []);
```

`observeKeysForOwner()` reads only the complete item keys from the unit-of-work view, returns them as
`MappedKey` objects and records the membership observation. Use `expectSameRowKeysForOwner()` to register
keys already known to the caller. Both owner and item keys accept associative column maps. Use line-level
observations to protect decisions about item values. An empty expectation
checks that a collection is empty. An empty reconciliation requests a cleanup even when no rows were buffered.
Repeated observations and later reconciliations preserve the first committed membership expectation. A later
reconciliation replaces the previous desired membership of that owner and discards superseded writes to
excluded items while keeping earlier expectations. Later writes by complete key can retain or remove an item
from the desired membership, including an unchanged item whose row update becomes a check only.
A reconciliation validates all supplied rows before modifying the buffer. Different owner keys are disjoint.

## Coordinated flush

Declare an owner dependency when a child row set references another defined row set. For example, when
`OrdersDefinition` declares the key `id`, the mapping for `OrderLinesDefinition` can pass
`owner: new MappedRowSetOwner(OrdersDefinition::class, ['order_id' => 'id'])` in place of `ownerColumns`.
The reference maps the complete parent key to immutable child key columns of the same PHP representation.
Symfony validates that the parent definitions exist and dependencies are acyclic, then emits the dependency
order and the set of referenced definitions in the container. Standalone coordination computes the same
schedule during assembly. Fixed owner columns remain available for collections whose owners do not impose a
dependency between row sets.

With Symfony, `MappedTableExtension` enrolls a single `MappedTableUnitOfWork` in the composite registered
by `UnitOfWorkExtension`. Inject the row sets by definition class to prepare operations. In standalone
assembly, construct `MappedTableUnitOfWork` with a map from definition classes to row sets. A coordinated
row set can belong to only one coordinator. Call the coordinator through the runner or explicitly inside
the caller's transaction. Pending plans must use one active connection or in-memory transaction.

For each flush, the coordinator takes a snapshot of every pending plan, validates the combined plan and
asks all relevant adapters to check membership and check-only row expectations. The adapters keep their
intrinsic locks and enforce update and delete predicates in the writes. Then the coordinator executes
explicit deletes and reconciliation cleanup in child-before-parent order, followed by inserts, updates and
upserts in parent-before-child order. Within each row set, explicit deletes precede its cleanup so they can
still validate their conditional predicates. An empty reconciliation executes a cleanup even when no child row
is buffered.

An owner dependency determines order. Applications request child cleanup explicitly. For existing children
outside the plan, an immediate foreign key can reject the parent deletion. The coordinator rejects a write
or retention of a child whose parent the plan leaves absent. It also rejects physical replacement of a row in
a referenced definition, since replacement deletes the row before recreating it. Owner moves and dependencies
on mutable columns are outside this model.

A successful flush consumes all applied plans together. A later rollback undoes durable changes. If the flush
fails partway, the caller rolls back and clears the pending buffers. The transactional runner handles both.
For low-level standalone row sets without a coordinator, adapters check membership, apply their own row
mutations and then clean up reconciliations within the caller's transaction.

## Reading and observing rows

`withKey()` addresses one row. It prepares no work until a caller registers an expectation or a mutation.
Use a scalar `int`, `string` or `Stringable` when exactly one key column remains unbound. Associative
arrays and `MappedKey` objects supply the complete key by column name. Their values can be integers,
strings or `Stringable` objects. A `MappedRow` accepts an associative key when constructed and exposes
its normalized key as a `MappedKey`.

```php
$line = $lines->withKey(['order_id' => 'o-42', 'line_id' => 'l-7']);
$sku = $line->get(['sku']);
$observed = $line->observe(['sku']);
```

Both methods return `null` when the row is absent. An omitted projection selects all columns, and an
empty list selects only the complete key. A non-empty list selects the named columns. Both methods
see buffered writes and deletions. They omit values that are unknown in this view, such as an unset
column of a pending insert. A known `null` is returned as a column value. A present row with no known
values in the projection returns an empty array.

`get()` only reads. `observe()` performs the same read once and observes presence or absence.
It adds value expectations only for returned non-key columns read from storage, including a stored
`null`. Buffered column values leave existing storage expectations in place. Use `observe([])` to
depend on existence alone.

Use a fresh handle after the buffer is flushed or cleared.

## Standalone adapter API

Direct construction without a mapping also supports adapters with a fixed scope.

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

`withKey($key)->delete()` buffers a conditional deletion, retaining existing column and presence expectations.
Without a prior absence expectation, the row must still exist at flush. Deleting an expected-absent row only
checks absence; a buffered insertion for that key is cancelled, and a competing creation still causes a conflict.

`rows(...)` returns a list of `MappedRow` objects containing a structured `MappedKey` and the projected columns. It overlays buffered mutations. `expectSameRowKeys(...)` buffers an optimistic expectation that the row-key set has not changed before `flush()`.

`reconcileRows(iterable $rows)` accepts desired `MappedRow` values, upserts their supplied columns and deletes
rows missing from that set within the adapter's scope at flush. Preparing reconciliation does not read storage.
The desired membership is reflected immediately in `withKey()->get()` and `rows()`, alongside buffered column changes.
An empty set clears the scope; successive calls supersede earlier membership and discard writes to removed rows.
Unspecified columns on retained rows and existing optimistic expectations are preserved. Repeated keys merge their supplied columns in input order, with the last supplied
value winning. Reconciliation does not register new optimistic expectations. Without prior expectations, it
imposes the saved membership, removing rows added to storage before the flush deletion. An earlier
`expectSameRowKeys()` still rejects concurrent membership changes. A key expectation registered after
reconciliation checks the local membership without replacing any earlier committed expectation. Later explicit
row writes can add or remove keys from the reconciled set. `clear()` discards the reconciliation.

### Expectations and the unit-of-work view

Row reads expose the unit of work's view with buffered changes applied. Expectations address that same view.

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

`flush()` consumes applied row plans, checked expectations and membership reconciliation after success, following
the [unit-of-work contract](../transactions/README.md#core-contract). Later reads use storage without the consumed
overlay; register fresh expectations for a new decision. An empty flush does not call the adapter.

`MappedRowSet` itself does not open transactions. If you need atomicity across a flush, open the surrounding transaction on the underlying storage before calling `flush()`.

For locally constructed projection row sets, see the
[projection-owned persistence](../projections/README.md#projection-owned-persistence).

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
for inspection. Defined row sets in a coordinated unit of work can participate in
[compiled query guards](query-guards.md#mappedtable-query-guards). The coordinator acquires these guards and
replays the recorded queries before checking and applying the writes. The persistence locks described above
stay local to each mapped adapter.

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
| Definitions compile to shared, adapter-backed row sets with a declared representation | [DefinedRowSetsTest](../../src/StoredState/Tests/MappedTable/DefinedRowSetsTest.php) | Dumped container, selected factory, codec selection and column validation. |
| Reads expose the unit-of-work view without changing storage | [BufferedViewTest](../../src/StoredState/Tests/MappedTable/BufferedViewTest.php) | Committed projections and structured rows, shared owner and direct overlays, check-only observations. |
| Writes have distinct insert, update, upsert, replacement and deletion semantics | [WriteSemanticsTest](../../src/StoredState/Tests/MappedTable/WriteSemanticsTest.php) | Preservation of unspecified values, conflicts, multiple deletions, replacement expectations, cancellation and merging. |
| Optimistic expectations protect the values, presence and membership a decision observes | [OptimisticConcurrencyTest](../../src/StoredState/Tests/MappedTable/OptimisticConcurrencyTest.php) | Local versus committed expectations, immediate conflicts, check-only flushes, final-plan table policies, concurrent insertions/deletions and first-expectation retention. |
| Saved membership supersedes earlier membership for its owner or fixed adapter scope | [MembershipReconciliationTest](../../src/StoredState/Tests/MappedTable/MembershipReconciliationTest.php) | Late rows, empty and successive sets, duplicate keys, retained columns, earlier expectations and later explicit operations. |
| Declared query guards protect decisions against affected coordinated writes | [QueryGuardTest](../../src/StoredState/Tests/MappedTable/QueryGuardTest.php) | Direct writes, scope moves, empty reconciliations, consolidation, strict replay and PostgreSQL participation. |
| Coordinated flush orders explicit child deletions and parent creation atomically | [FlushCoordinationTest](../../src/StoredState/Tests/MappedTable/FlushCoordinationTest.php) | Composite owner keys, immediate foreign keys, checks before writes, rollback and reuse, one active transaction. |
| Preparation, flush, clear and transactions retain their separate responsibilities | [FlushLifecycleTest](../../src/StoredState/Tests/MappedTable/FlushLifecycleTest.php) | Preparation without I/O, sorted immutable snapshots, check-only writes, successive flushes consuming completed work, complete cleanup, external rollback and in-memory transaction snapshots. |
| Keys and scopes address independent rows | [KeyAndScopeIsolationTest](../../src/StoredState/Tests/MappedTable/KeyAndScopeIsolationTest.php) | Composite keys, scalar and Stringable shortcuts, colliding IDs, multiple scope columns and invalid addresses. |
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
