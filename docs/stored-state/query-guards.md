# Query guards

`QueryGuard` protects decisions based on queries over mapped rows. The developer declares the states, scope
properties and property or collection dependencies that affect a query. Readers request shared guards; `StateTableRegistry` automatically
derives exclusive guards for affected writers, including actions that never call the query.

The decision reads storage without acquiring locks. Flush acquires the guards, replays the recorded queries and
applies the row plans inside one transaction. Guards complement the optimistic row expectations registered by
`StateStore::observe()`; they do not register those expectations themselves.

## Declaration

```php
use Ultra\StateStore\Attribute\DefineQueryGuard;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\QueryGuardDefinition;

public function __construct(
    #[DefineQueryGuard('company_usage', static function (QueryGuardDefinition $definition): void {
        $definition
            ->watch(Booking::class, scopeBy: 'companyId')
            ->whenPropertiesChange('charged', 'start', 'end');
    })]
    private QueryGuard $companyUsage,
) {}
```

The declaration uses mapped property paths, resolved to physical columns at compilation. `scopeBy` selects
one integer or string property whose value identifies a guard; it is not necessarily the state's identifier.
Use the identifier property name for a scalar ID, `id.tenantId` for a composite ID component, and `address.city`
for an embedded property. Physical column names are not accepted as property paths.

`whenPropertiesChange()` selects properties affecting the query; their dependencies combine as an OR.
A collection property name watches its entire contents. Repeated calls accumulate dependencies.
Omitting this method watches all mapped properties and collections. Paths into collection elements such as
`permissions.active` are not supported.
Insertions, deletions and changes to the scope always participate for the root row, even for a collection-only
watch. Replacements and blind upserts conservatively participate too.

```php
$definition
    ->watch(RoleState::class, scopeBy: 'id.tenantId')
    ->whenPropertiesChange('active', 'permissions');
```

Collection dependencies watch item additions, deletions and value changes, as well as membership reconciliation,
including empty collections. A reconciliation conservatively participates even if its desired contents are
unchanged. For a watched collection, `scopeBy` must reference the owner's identifier or a component of it:
the collection's `ownerColumns` mapping determines the physical scope column. Other scope properties are
rejected at compilation because item and empty-collection plans cannot recover them without another read.
Collection observations without mutations do not request writer guards.

The name identifies the same guard across repositories and actions. A definition can call `watch()` several times
to cover multiple registered states. Repeated declarations of the same name must compile to the same definition.
Property order, collection order and duplicate dependencies are normalized during compilation.

`StateStore_Bundle` discovers constructor declarations on autowired services, executes their configuration
closures during compilation, registers global write rules, and injects each guard into its parameter. Only plain
metadata is retained in the dumped container. See the [bundle configuration](symfony.md) for
service registration and compilation errors.

## Decision reads

At runtime, `observe()` and `lock()` require a registered definition with at least one watched state. An unknown
name or empty definition throws `UnknownQueryGuardException` before reading data or queuing work. The exception
identifies the guard and explains how to register it. This also applies to requests queued directly through
`StateTableRegistry::guard()`. Manually constructing a `QueryGuard` does not register writer dependencies.
Manual row guards requested through `StateStore::lock()` remain independent of query-guard definitions.

```php
$minutes = $this->companyUsage->observe(
    $company->id,
    fn (): int => $this->usedMinutes($company, $month),
);
```

`observe()` first checks the current row plans for mutations affecting this guard and scope. It rejects an
impacting mutation with `PendingQueryChangesException`, before executing the callback or registering any reader
request. The exception identifies the guard, scope, table mapping and row key. Insertions, deletions and both old
and new scope values of a move participate; integer and string values share the same advisory-lock identity.
Changes in another scope, unwatched columns, other tables and check-only expectations remain allowed. Unknown
previous scopes are rejected using the same validation as writer guards; no database reads are added to infer them.
The check is conservative over declared dependencies, without parsing the callback's SQL predicates.

An allowed `observe()` declares a shared guard, executes the callback now, records the result for replay at flush,
and returns it. `lock($value)` only declares a shared guard; the caller must separately record any query expectation.
Neither acquires a database lock during the decision. Both requests count as pending unit-of-work work, even
without a write.

Callbacks must be read-only, repeatable, use the flush connection and capture stable parameters. They must read
storage, without the pending write overlay, and return scalars, null or arrays of those values. Results are compared
with `===`: cast database numeric values, normalize dates and sort unordered query results in the callback.
Objects, including state objects, are not valid expected results. The pending-write check applies
only to the initial decision read. Replaying an earlier observation at flush remains valid after affected writes
have been prepared, because replay precedes applying those writes.

Coworking validates every weekly occurrence and sums requested minutes by month before saving any booking.
It reads the existing usage once per month and validates it against the whole request. Its repository keeps no
local overlay and does not participate in unit-of-work cleanup. Composing a cancellation and a new booking in
the same affected scope before flush is deliberately rejected; prepare a complete decision before its writes.

## Flush protocol

The StateStore unit of work derives exclusive guards from its final row plans, even when no query or
repository method was called. It protects both old and new scope values for a move. Unchanged watched columns do
not require a guard when the plan proves their values unchanged.

`StateTableRegistry` owns the pending manual and named guards, independently of mapped row sets. It inspects
`MappedRowSet::updatePlan()` to derive writer requests through `QueryGuardWriteRules`, which only returns requests
and does not mutate row sets. `QueryGuard` queues reader requests directly in the registry. The single coordinated
flush transaction then:

1. Consolidates named guards across tables and views, with exclusive dominating shared for the same name/value.
2. Acquires named guards in name/value order, followed by any legacy manual row guards in table/key order.
3. Replays query expectations.
4. Checks optimistic row expectations and applies writes.

Each flush consolidates modes before acquisition, avoiding a shared-to-exclusive upgrade within that flush.
PostgreSQL uses transaction-scoped advisory shared and exclusive locks in the same query-guard namespace,
separate from manual row and automatic absent-row guards.
`GuardLockAdapter` owns acquisition; `PostgresGuardLockAdapter` must use the same connection as the mapped
adapters and query callbacks. `InMemoryGuardLockAdapter` requires an active transaction without simulating
contention. The bundle wires the adapter when registered and rejects compiled guards if it is missing.
Mapped row sets know neither decision guards nor query replay; their adapters retain only intrinsic persistence
locks (row checks, absent keys and row-set expectations).
Commit or rollback releases database locks; unit-of-work `clear()` removes queued guards and expectations.

Use `TransactionalUnitOfWorkRunner` with a transaction manager for the same connection. It prepares the decision
before opening the flush transaction and clears the unit of work after success or failure. When calling `flush()`
directly, the caller owns the transaction, its rollback on failure and the final `clear()`. `flush()` itself neither
starts a transaction nor clears pending work. On PostgreSQL, use `READ COMMITTED` so each replay sees commits made
before acquiring its guards; advisory locks do not refresh an earlier transaction snapshot. The adapter does not
validate the isolation level.

## Errors

| Exception | Meaning and caller responsibility |
|---|---|
| `Ultra\StateStore\Exception\UnknownQueryGuardException` | `observe()` or `lock()` uses a name without a non-empty registered definition. Correct the declaration or manual registration. The callback is not executed and no request is queued. |
| `Ultra\StateStore\Exception\PendingQueryChangesException` | An initial read would ignore a pending write in the same declared scope. Decide before preparing affected writes. The exception exposes `guardName`, `guardValue`, `tableKey` and `key`. |
| `Ultra\UnitOfWork\Exception\ConcurrencyException` | A replayed query result differs from its expected value. Roll back the flush and retry the whole decision from fresh reads if appropriate; retry is not automatic. |
| `LogicException` | An affected write lacks its previous scope, uses a non-scalar scope or is a blind upsert; or guard acquisition has no adapter or active transaction. Correct the observation, write plan or configuration. |
| `InvalidArgumentException` | `whenPropertiesChange()` was called without names, or an expected query result contains objects or other unsupported values. Correct the declaration or normalize the result. |

Unknown previous scopes can fail during either an initial read or flush. This failure must not be interpreted as
an unrelated mutation. Optimistic row conflicts still use the mapped-table concurrency checks. An advisory guard
does not replace the row's presence and column expectations.

## Manual construction

Without the bundle, compile the same builder against the registered state metadata:

```php
$definition = new QueryGuardDefinition();
$definition->watch(Booking::class, scopeBy: 'companyId')
    ->whenPropertiesChange('charged', 'start', 'end');

$tables = new StateTableRegistry(
    [$bookingMetadata->tableKey => $bookingRows],
    queryGuardDefinitions: ['company_usage' => $definition->compile($metadataRegistry)],
    guardLockAdapter: $guardAdapter,
);
```

`compile()` returns plain physical writer rules. Supply the same table keys and metadata as the registry's root
row sets, and an adapter factory when using collections. Hand-written physical rule arrays remain possible,
but bypass the builder's property and collection validation.

## Supported scope and developer responsibilities

- Dynamic guard values are one non-null integer or string. Integer and string forms with the same textual value
  identify the same lock. Composite scopes, month/day derivation and arbitrary key functions are not supported.
  Coworking guards cover a whole company or room.
- Existing rows must have an optimistic expectation on their scope column before an affected mutation. Observe
  absence before inserting through StateStore. A scope excluded from optimistic observation needs an explicit
  column expectation. Blind upserts are rejected when their previous scope is unknown; an identifier scope is
  already known from the immutable row key. No additional database reads are used to discover old scopes.
- Write rules apply to root row tables and declared collection tables managed by `StateTableRegistry`.
  Independently flushed MappedRowSet instances and direct SQL writes are not automatically enrolled.
- Queries use shared mode and mutations use exclusive mode. Inverse protocols are not configurable.
- The developer must declare every dependency affecting the query and ensure all writers follow the same
  protocol. SQL is not parsed. Guards do not replace database constraints or optimistic state observations.
- Acquisition order covers the guards in one flush. Other database writers, constraints and locks from earlier
  flushes can still introduce deadlocks. Clearing the unit of work does not release transaction locks.
- Query results are checked before local writes. Initial reads with pending affected writes are rejected; no
  generic overlay, original-state reconstruction or read-your-own-writes query engine is provided.
- `QueryGuard::lock()` only requests a guard; it does not execute a query or enforce the initial-read check.
  Raw `StateStore::observeQuery()` has no dependency metadata and cannot apply this check.

The [coworking repository](../../examples/coworking/src/Booking/StateStoreDirect/Repository/BookingRepository.php)
demonstrates quota and availability queries. Email registration uses an unguarded SQL expectation and a database
unique constraint. Query callbacks execute once during the decision and again at flush; no throughput or latency
guarantee is made without a benchmark.

## Tests

For suite ownership, see [StateStore test guarantees](README.md#test-ownership-and-maintenance).

- The existing coworking scenarios and competing-kernel races pass after removing all service-level manual guards.
- Two independent Postgres connections verify shared reader compatibility, reader/writer exclusion, protection of
  insertion/deletion/move scopes before query validation, and exclusive consolidation across mapped views.
- Initial observations reject pending affected writes before invoking the callback, while unrelated scopes,
  columns, tables and optimistic expectations remain allowed. Flush replay accepts subsequently prepared writes.
- A change to an unrelated column can proceed while a query guard is held.
- A dumped Symfony container injects a working guard and rejects a changed query result at flush.
- A writer that never invokes the guarded repository still follows the compiled rules.

Run the focused framework tests from the repository root, with the local Postgres fixture running:

```bash
bin/phpunit src/StateStore/Tests
```

Run the application comparison from `examples/coworking` with `vendor/bin/phpunit`.
