# Compiled query guards — prototype

This PHP prototype evaluates constructor injection of a named query guard, compile-time dependency declarations,
and automatic participation of writers. It exercises the existing Postgres flush, rather than simulating locks.
The interface is experimental; it is not a finalized persistence contract.

## Interface

```php
public function __construct(
    #[DefineQueryGuard('company_usage', static function (QueryGuardDefinition $definition): void {
        $definition
            ->watch(Booking::class, keyBy: 'company_id')
            ->whenColumnsChange('charged', 'start_at', 'end_at');
    })]
    private QueryGuard $companyUsage,
) {}
```

The declaration uses physical column names, validated against the state's compiled mapping. `keyBy` selects the
column whose value identifies a dynamic guard. It is not necessarily the primary key. `whenColumnsChange()`
selects mutations affecting the query; omitting it includes every mutation. Insertions, deletions and changes to
the scope column always participate. A replacement conservatively participates too.

The bundle discovers constructor declarations on autowired services, executes their static configuration closures
during compilation, registers global write rules, and injects each guard into its parameter. Only plain metadata
is retained in the dumped container.
Declarations with unknown columns or conflicting definitions for the same name fail compilation. No repository
lookup or registry is exposed to the application's query method.

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
Neither acquires a database lock during the decision. Callbacks must read committed storage, without the pending
write overlay, and return stable scalars or arrays suitable for strict comparison. The pending-write check applies
only to the initial decision read. Replaying an earlier observation at flush remains valid after affected writes
have been prepared, because replay precedes applying those writes.

Coworking validates every weekly occurrence and sums requested minutes by month before saving any booking.
It reads the existing usage once per month and validates it against the whole request. Its repository keeps no
local overlay and does not participate in unit-of-work cleanup. Composing a cancellation and a new booking in
the same affected scope before flush is deliberately rejected; prepare a complete decision before its writes.

## Flush protocol

The normal StateStore unit of work derives exclusive guards from its final row plans, even when no query or
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

No shared-to-exclusive upgrade occurs after acquisition. PostgreSQL uses transaction-scoped advisory shared and
exclusive locks in the same query-guard namespace, separate from manual row and automatic absent-row guards.
`GuardLockAdapter` owns acquisition; `PostgresGuardLockAdapter` must use the same connection as the mapped
adapters and query callbacks. `InMemoryGuardLockAdapter` requires an active transaction without simulating
contention. The bundle wires the adapter when registered and rejects compiled guards if it is missing.
Mapped row sets know neither decision guards nor query replay; their adapters retain only intrinsic persistence
locks (row checks, absent keys and row-set expectations).
Commit or rollback releases database locks; unit-of-work `clear()` removes queued guards and expectations.

## Evidence

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
ULTRA_TEST_POSTGRES_DSN='pgsql://ultra:ultra@127.0.0.1:55432/ultra_test' bin/phpunit src/StateStore/Tests/QueryGuardPrototypeTest.php src/StateStore_Bundle/Tests/StateStoreExtensionTest.php
```

Run the application comparison from `examples/coworking` with `vendor/bin/phpunit`.

## Limits and decisions left open

- Dynamic guard values are one non-null integer or string. Composite scopes, month/day derivation and arbitrary
  key functions are not implemented. Coworking guards cover a whole company or room.
- Existing rows must have an optimistic expectation on their scope column before an affected mutation. Observe
  absence before inserting through StateStore. Blind upserts are rejected because their previous scope is unknown.
  No additional database reads are used to discover old scopes.
- Write rules run through `StateTableRegistry::flush()` for its registered row tables. Independently flushed
  MappedRowSet instances, collection-item tables and direct SQL writes are not automatically enrolled.
- The prototype fixes query mode to shared and mutation mode to exclusive. Configurable inverse protocols are
  deferred. Ordinary row-level concurrency checks remain necessary for overlapping writes.
- Completeness of the declared dependencies remains the developer's responsibility. SQL is not parsed. Lock
  ordering covers these guards; it does not establish that all database writers and constraints are deadlock-free.
- Query results are checked before local writes. Initial reads with pending affected writes are rejected; no
  generic overlay, original-state reconstruction or read-your-own-writes query engine is provided.
- `QueryGuard::lock()` only requests a guard; it does not execute a query or enforce the initial-read check.
  Raw `StateStore::observeQuery()` has no dependency metadata and cannot apply this check.
- Email registration keeps the existing unguarded SQL expectation and database unique constraint.

The prototype supports the proposed interface and removes the need to remember guards in each booking action.
The remaining design questions are dependency completeness, handling writes without known old scopes, and the
granularity of derived keys. There is no performance conclusion without a benchmark.
