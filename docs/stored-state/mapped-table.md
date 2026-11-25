# Mapped Table

Adapter-backed row-set unit of work for explicitly buffered row operations.

## Purpose

`Ultra\MappedTable\MappedRowSet` buffers row-level changes. `MappedTableUnitOfWork` flushes all of its
row sets through one adapter bound to a single storage resource.

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

A row set selects a table declared in [Schema](../schema/README.md) by its stable ID. Schema
provides the physical column names, nullability and complete primary key. The row set selection can expose a
subset of columns and always includes the primary key. After decoding, supported PHP representations are
`int`, `string`, `bool`, `float` and normalized arrays of scalar values, null and nested arrays. A key column
is a non-null integer or string. Row writes assign only non-key columns. A value supplied by a database
default becomes available after persistence.

The owner columns, when present, identify one collection for each complete owner key in the child table's
column names. They leave at least one further key column for the item. Reads by owner and direct writes by
complete row key use the same buffer.

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

## Compile row sets from Schema

With Symfony, activate each row set by its stable Schema table ID. The ID is also the Symfony service ID
for injection, query guards and projection ownership. Symfony assembles the schema through
[`#[DefineSchema]`](../schema/README.md#symfony-discovery).

```php
new MappedTableExtension(rowSets: [
    WorkshopSchema::WORKSHOPS,
    WorkshopSchema::RESERVATIONS => ['owner' => WorkshopSchema::WORKSHOP_REFERENCE],
])->build($container);
```

Bind `MappedTableAdapter` to a shared service for the chosen storage and declare its concrete class,
including for synthetic services. A table used only for migrations needs no row-set activation.

A selected table needs a structured primary key. Schema supplies the physical names, nullability and SQL types.
Each exposed column binds one [codec](#column-codecs) by its SQL type at compilation. The row set exposes
every column by default. To limit exposure, set `columns` to a list of column IDs. The primary key is always exposed. Compilation
reports the table, column and type if an exposed column uses an unsupported SQL type.

Set `owner` to the name of a declared foreign key to make it a Mapped Table owner relationship. Activate the
parent row set too. The foreign key must use immutable child key columns and leave at least one item key column.
For collections without a mapped persistence dependency, set `ownerColumns` to those immutable child key IDs.

For manual assembly, call `$adapter->compileRowSet($schema, $tableId)`. Use the same options as named arguments.
The factory binds the mapping to the adapter and creates a fresh buffer on each invocation. Compilation prepares
the mapping and, for PostgreSQL, the SQL fragments once. Reuse the bound row set inside its unit of work.

The [Symfony workshop example](../../examples/mapped-table-symfony/README.md) generates the PostgreSQL DDL
and compiles two row sets from one schema, then exercises owner collections and a query guard.

## Coordinated flush

An owner dependency maps the complete parent key to immutable child key columns of the same PHP
representation. Symfony validates that the parent row sets exist, then emits the dependency order and the
set of referenced definitions in the container. Manual assembly validates the same dependencies and computes
the order when creating the unit of work.

With Symfony, `MappedTableExtension` enrolls one application `MappedTableUnitOfWork` in the composite
registered by `UnitOfWorkExtension`. Row sets declared as
[projection-owned](../projections/README.md#mapped-row-sets-in-symfony) use the projection's private
coordinator. Inject the row sets by their Symfony service IDs to prepare operations. In manual assembly, create
the row sets through one adapter and pass them with that adapter to `MappedTableUnitOfWork`. For declared
dependencies and guards, key the map by row set ID. A row set belongs to one coordinator. Call
the coordinator through the runner or explicitly inside a transaction active on the adapter's resource.

For each flush, the coordinator takes a snapshot of every pending plan, validates the combined plan and
acquires the query guards before replaying observed queries. It calls its adapter with the complete ordered
plan. The adapter checks membership and check-only row expectations, takes intrinsic locks and enforces
update and delete predicates in the writes. Explicit deletes and reconciliation cleanup run in
child-before-parent order, followed by other writes in parent-before-child order. Within each row set,
explicit deletes precede cleanup so they can validate their conditional predicates. An empty reconciliation
requests cleanup even when no child row is buffered.

An owner dependency determines order. Applications request child cleanup explicitly. For existing children
outside the plan, an immediate foreign key can reject the parent deletion. The coordinator rejects a write
or retention of a child whose parent the plan leaves absent. It also rejects physical replacement of a row in
a referenced definition, since replacement deletes the row before recreating it. Owner moves and dependencies
on mutable columns are outside this model.

A successful flush consumes all applied plans together. A later rollback undoes durable changes. If the flush
fails partway, the caller rolls back and clears the pending buffers. The transactional runner handles both.

## Reading and observing rows

`withKey()` returns a `MappedRowHandle` for one row. It prepares no work until a caller registers an
expectation or a mutation.
Use a scalar `int`, `string` or `Stringable` for a single-column key. Associative
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

`observeMany($keys, $columns)` observes known keys of one table with a shared storage read and the
same projection and provenance rules. It returns a list in input order, including repeated keys and
`null` for each missing row. An empty input returns an empty list. A conflict while preparing the
group leaves its new expectations unapplied. The operation observes only the supplied keys.
Use a membership observation or query guard when the decision depends on how the keys were selected.

Use a fresh handle after the buffer is flushed or cleared.

`rowsForOwner($owner, $columns)` returns the owner's rows as `MappedRow` objects with a separate complete key.
Omit `$columns` or pass `null` to return all mapped non-key columns. Pass `[]` for the complete keys alone
with empty `columns` maps. A non-empty projection can explicitly include key columns. The rows reflect
buffered changes, omit unknown values, and preserve known nulls.

## Manual assembly and whole-table membership

Pass a complete mapping to the shared adapter when assembling row sets manually. Use one adapter and
one unit of work, including when the unit of work contains a single row set.

```php
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

$database = new InMemoryRowDatabase();
$adapter = new InMemoryMappedTableAdapter($database);
$schema = new SchemaDefinition([
    new TableDefinition('users', columns: [
        new ColumnDefinition('id', type: ColumnType::Text),
        new ColumnDefinition('status', type: ColumnType::Text),
    ], primaryKey: ['id']),
]);
$table = $adapter->compileRowSet($schema, 'users')();
$unitOfWork = new MappedTableUnitOfWork(['users' => $table], $adapter);
$database->seedRow('users', 'id', ['id' => 'user-1', 'status' => 'pending']);

$table->withKey('user-1')
    ->expectColumnValue('status', 'pending')
    ->setColumn('status', 'active');

$database->transactional($unitOfWork->flush(...));
```

`withKey($key)->delete()` buffers a conditional deletion, retaining existing column and presence expectations.
Without a prior absence expectation, the row must still exist at flush. Deleting an expected-absent row only
checks absence; a buffered insertion for that key is cancelled, and a competing creation still causes a conflict.

A row set without owner columns supports whole-table membership operations.
`expectSameRowKeys(...)` buffers an optimistic expectation that the table's row-key set has not changed before
`flush()`. Row sets with owner columns use `expectSameRowKeysForOwner()` and `reconcileForOwner()`.

`reconcileRows(iterable $rows)` accepts desired `MappedRow` values, upserts their supplied columns and deletes
rows missing from that set across the table at flush. Preparing reconciliation does not read storage.
The desired membership is reflected immediately in `withKey()->get()` alongside buffered column changes.
An empty set clears the table at flush. Successive calls supersede earlier membership and discard writes to
removed rows.
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

Row expectations count as pending changes and are checked at flush. For a row without writes, the row set
produces a check-only plan (`RowUpdatePlan::MODE_EXPECT`, `writes()` is false). An update that sets each column
to its expected value also produces a check-only plan.

`withKey($key)->expectPresent()` checks presence without comparing columns. Buffered writes satisfy it locally;
otherwise the committed row must exist at flush. A buffered deletion or a conflicting absence expectation is
rejected immediately. This also guards states whose mapping contains only their identifier.

`expectSameRowKeys(...)` keeps its first committed expectation. Keys whose presence was decided by buffered
changes at observation time appear in that membership entry's `ignoredRowKeys`. Their row plans guard them.

## Adapter contract

One `Ultra\MappedTable\MappedTableAdapter` binds table configurations to its storage resource. Row sets
use it for committed reads and the unit of work uses it to acquire cooperative guards. The unit of work
verifies the active transaction for every non-empty flush and passes the adapter all
pending plans by physical table in dependency order. The adapter owns the execution phases and table-level
storage operations.

The immutable types under `Ultra\MappedTable\Plan` describe the sorted row operations and membership entries. Each membership entry carries
its owner key (or `null` for the whole table), the expected and ignored keys, and the final retained keys
when reconciliation was requested. An empty retained set removes all rows covered by the entry.

`flush()` consumes applied row plans, checked expectations and membership reconciliation after success, following
the [unit-of-work contract](../transactions/README.md#core-contract). Later reads use storage without the consumed
overlay; register fresh expectations for a new decision. An empty flush does not call the adapter.

The caller opens and completes the transaction on the adapter's resource.

For locally constructed projection row sets, see the
[projection-owned persistence](../projections/README.md#projection-owned-persistence).

### Table policy

`MappedTablePolicy` defines the operations a table supports, independently of its storage backend.
Both included adapters accept `tablePolicies`, keyed by table name, and apply each policy to manual and
compiled row sets. Unspecified tables use the default policy,
which allows check-only absence observations.

```php
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\MappedTablePolicy;
use Ultra\MappedTable\PostgresMappedTableAdapter;

$tablePolicies = [
    'course_names' => new MappedTablePolicy(allowAbsentObservations: false),
];
$postgres = new PostgresMappedTableAdapter($connection, tablePolicies: $tablePolicies);
$memory = new InMemoryMappedTableAdapter($database, tablePolicies: $tablePolicies);
```

Only the adapters receive the policy. `MappedRowSet` buffers expectations and mutations, and `updatePlan()`
remains an inspectable snapshot without lock instructions or policy validation. At flush, both adapters
validate the final plan through the policy before checking storage or applying writes. When absence observations
are disabled, a check-only absence plan throws `LogicException`. `expectAbsent()->insert()` remains allowed;
`expectAbsent()->insert()->delete()` becomes a check-only absence plan and is rejected. Contradictions with
buffered operations or expectations can still fail immediately when registered.

All adapters accessing the same physical table must use the same policy across processes. An adapter's
concurrency protocol relies on every writer participating when absence observations
are allowed. The application configures the policy.
Each backend owns the mechanisms needed to enforce the permitted expectations and writes.

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
use Ultra\MappedTable\PostgresMappedTableAdapter;

$adapter = new PostgresMappedTableAdapter($connection);
$table = $adapter->compileRowSet($schema, $tableId)();
```

The Postgres adapter checks expected column values with `IS NOT DISTINCT FROM`, so `null` expectations are handled correctly.
Row-set key expectations use a transaction-scoped advisory lock and therefore require an active transaction.

Check-only rows are checked in one `SELECT ... FOR SHARE` per table, so they stay locked against concurrent writers until the transaction ends. An absent row cannot be locked, so rows expected absent, and rows an insert or upsert may create, take a transaction-scoped advisory lock on their key (one statement, keys in sorted order): an absent check holds back a concurrent creation of the row until its transaction ends, and the other way around.

When the table policy disables [absence observations](#table-policy), inserts and upserts skip these per-key
advisory locks. SQL constraints, affected-row checks, present-row locks, row-set expectations and caller-owned
transactions remain in force.

`MappedRowSet` buffers row mutations and optimistic expectations. `updatePlan()` exposes a detached snapshot
for inspection. Defined row sets in a coordinated unit of work can participate in
[compiled query guards](query-guards.md#mappedtable-query-guards). The coordinator acquires these guards and
replays the recorded queries before checking and applying the writes. The persistence locks described above
are managed by the adapter.

### Column types

Mapped Table provides built-in codecs for these PostgreSQL types. The table shows their non-null PHP values.

| Schema declaration or PostgreSQL spelling | PHP representation |
| --- | --- |
| `ColumnType::Text`, `text` | `string` |
| `varchar`, `varchar(n)`, `character varying` | `string` with the declared character limit |
| `ColumnType::Int32`, `integer`, `int`, `int4` | `int` in the 32-bit signed range |
| `ColumnType::Int64`, `bigint`, `int8` | `int` |
| `ColumnType::Boolean`, `boolean`, `bool` | `bool` |
| `uuid` | `string` |
| `date` | Date string |
| `timestamp`, `timestamp without time zone` | Local date and time string |
| `timestamptz`, `timestamp with time zone` | Date and time string with a UTC offset |
| `text[]`, `uuid[]`, `integer[]`, `int[]`, `int4[]` | PHP list of elements of the corresponding scalar type or `null` |
| `jsonb` | Decoded JSON value |

A nullable column accepts PHP `null` for SQL `NULL`. Codecs prepare buffered values, encode SQL parameters,
and decode database results. Key columns must use a non-null integer, text, varchar or UUID type.
UUID keys use a canonical lowercase spelling, including in collection keys and buffered updates.

UUID columns accept strings and `Stringable` values, including Symfony UUIDs. Date and timestamp columns
accept strings and `DateTimeInterface`. Mapped Table converts date objects to `YYYY-MM-DD` and timestamp
objects to SQL date and time strings with microseconds. A `timestamp` keeps the object's local time.
A `timestamptz` includes the object's offset. Outside UUID keys, supplied strings are kept as written.
PostgreSQL interprets those strings and applies column precision. Committed reads return the spelling
provided by PostgreSQL, which depends on the session settings.

Buffered expectations recognize equivalent UUID spellings. ISO timestamps with seconds compare by
local date and time. ISO `timestamptz` strings with explicit offsets compare by instant.
Other date and time strings compare as written in the buffer and in the in-memory adapter.
PostgreSQL evaluates committed expectations using SQL equality. Use the observed value for an
expectation that depends on SQL rounding or the database session's timezone.

A `jsonb` column accepts lists, associative arrays, `stdClass` objects, strings, booleans, and finite
PHP integers or floats. Objects decode as associative arrays when those arrays encode back to objects.
Use `stdClass` for objects such as `{}` or `{"0":"a"}`, whose PHP arrays would encode as lists.
Use `Ultra\MappedTable\JsonNull::Value` for JSON `null` at the root. Inside a JSON document, use PHP
`null` for JSON `null`. JSON object property order is ignored in comparisons. List order matters.
Reading a JSON number fails if decoding it as a PHP `int` or `float` and encoding it again changes its
decimal value.

```php
$schema = new SchemaDefinition([
    new TableDefinition('acceptances', columns: [
        new ColumnDefinition('id', type: ColumnType::Uuid),
        new ColumnDefinition('accepted_at', type: SqlType::timestampWithTimeZone(precision: 3)),
        new ColumnDefinition('evidence', type: ColumnType::Jsonb, nullable: true),
    ], primaryKey: ['id']),
]);
$acceptances = $adapter->compileRowSet($schema, 'acceptances')();
```

### Column codecs

Register a `ColumnCodec` when a SQL type needs a different PHP representation or lacks a built-in
codec. Schema declarations carry the SQL type. Mapped Table binds the codec when it compiles the row set.

In standalone PHP, supply codec instances keyed by SQL type.

```php
$rows = $adapter->compileRowSet($schema, 'records', codecs: [
    'hstore' => $attributesCodec,
])();
```

In Symfony, tag a codec service with its SQL type. The compiled row set references that service,
including its configured constructor dependencies.

```php
$container->register(AttributesCodec::class)
    ->setAutowired(true)
    ->addTag(MappedTableExtension::COLUMN_CODEC_TAG, ['type' => 'hstore']);
```

An application registration takes precedence over the built-in codec for the same SQL type.
Schema's canonical type identity determines the association. For example, `int4` and `integer` share
one registration. SQL modifiers take part in matching, so an association for `varchar(10)` applies
to that length. Two application codecs for the same canonical type are a configuration error.
For a type declared as a literal SQL name in Schema, use the same declaration as the registration key.

The codec declares its PHP representation with `representation()` and converts application input
with `normalize()`. Normalization accepts the codec's own output, and any returned arrays or JSON
objects must be detached from caller-owned mutable values. By default, scalar values are encoded as
SQL parameters, database values are normalized on read, and buffered expectations use strict equality.
Override `encode()`, `decode()` or `equivalent()` when the type needs different behavior. A codec can
opt into keys with `supportsKeys()` if its `int` or `string` keys share one stable spelling per SQL
identity. Override `key()` when key canonicalization differs from ordinary normalization.

## SQL inspection

The [standalone SQL inspection example](../../examples/mapped-table-sql/README.md) assembles an adapter and
a unit of work with one row set, captures its pending plan and traces PostgreSQL through an opt-in DBAL middleware. It
includes scenarios for grouped writes, check-only observations and optimistic conflicts, with isolated
temporary data and automatic cleanup. The example owns setup, authoring and report instructions; the storage
and transaction contracts remain unchanged.

## Test ownership and maintenance

Each suite owns one guarantee and its cases. Tests are not grouped by implementation class or physical table.
The shared fixture sets up storage, performs independent committed reads and cleans up transactions; it contains
no assertions or model of the expected behavior. Common persistence cases use a backend data provider so the
same scenario exercises the in-memory and Postgres adapters.

| Guarantee | Suite | Cases |
| --- | --- | --- |
| Definitions compile to shared, adapter-backed row sets with inferred representations | [DefinedRowSetsTest](../../src/StoredState/Tests/MappedTable/DefinedRowSetsTest.php) | Dumped container, shared adapter and schema-derived SQL. |
| Schema tables are activated and compiled explicitly | [SchemaRowSetCompilationTest](../../src/StoredState/Tests/MappedTable/SchemaRowSetCompilationTest.php) | Manual assembly, a service alias, unknown types, temporal keys and owner selection. |
| Application codecs bind to a canonical SQL type | [ColumnCodecRegistrationTest](../../src/StoredState/Tests/MappedTable/ColumnCodecRegistrationTest.php) | Standalone registration, tagged Symfony services, alias overrides and duplicate registrations. |
| Supported values round-trip with matching key and expectation semantics | [ColumnValueRoundTripTest](../../src/StoredState/Tests/MappedTable/ColumnValueRoundTripTest.php) | UUID keys and object inputs, timestamp comparisons and precision, arrays and JSONB values. |
| Reads expose the unit-of-work view without changing storage | [BufferedViewTest](../../src/StoredState/Tests/MappedTable/BufferedViewTest.php) | Committed projections and structured rows, shared owner and direct overlays, check-only observations. |
| Writes have distinct insert, update, upsert, replacement and deletion semantics | [WriteSemanticsTest](../../src/StoredState/Tests/MappedTable/WriteSemanticsTest.php) | Preservation of unspecified values, conflicts, multiple deletions, replacement expectations, cancellation and merging. |
| Optimistic expectations protect the values, presence and membership a decision observes | [OptimisticConcurrencyTest](../../src/StoredState/Tests/MappedTable/OptimisticConcurrencyTest.php) | Local versus committed expectations, immediate conflicts, check-only flushes, final-plan table policies, concurrent insertions/deletions and first-expectation retention. |
| Saved membership supersedes earlier membership for its owner or the whole table | [MembershipReconciliationTest](../../src/StoredState/Tests/MappedTable/MembershipReconciliationTest.php) | Late rows, empty and successive sets, duplicate keys, retained columns, earlier expectations and later explicit operations. |
| Declared query guards protect decisions against affected coordinated writes | [QueryGuardTest](../../src/StoredState/Tests/MappedTable/QueryGuardTest.php) | Direct writes, changing guard values, empty reconciliations, consolidation, strict replay and PostgreSQL participation. |
| Coordinated flush orders explicit child deletions and parent creation atomically | [FlushCoordinationTest](../../src/StoredState/Tests/MappedTable/FlushCoordinationTest.php) | Composite owner keys, immediate foreign keys, checks before writes, rollback and reuse, one active transaction. |
| Preparation, flush, clear and transactions retain their separate responsibilities | [FlushLifecycleTest](../../src/StoredState/Tests/MappedTable/FlushLifecycleTest.php) | Preparation without I/O, sorted immutable snapshots, check-only writes, successive flushes consuming completed work, complete cleanup, external rollback and in-memory transaction snapshots. |
| Complete keys and owner keys address independent rows and collections | [KeyIsolationTest](../../src/StoredState/Tests/MappedTable/KeyIsolationTest.php) | Composite keys, scalar and Stringable shortcuts, colliding IDs and invalid addresses. |
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
