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

`observe()` declares a shared guard, executes the callback now, records the result for replay at flush, and returns
it. `lock($value)` only declares a shared guard; the caller must separately record any query expectation.
Neither acquires a database lock during the decision. Callbacks must read committed storage, without the pending
write overlay, and return stable scalars or arrays suitable for strict comparison.

## Flush protocol

The normal StateStore unit of work derives exclusive guards from its final row plans, even when no query or
repository method was called. It protects both old and new scope values for a move. Unchanged watched columns do
not require a guard when the plan proves their values unchanged.

The single flush transaction then:

1. Consolidates named guards across tables and views, with exclusive dominating shared for the same name/value.
2. Acquires named guards in name/value order, followed by any legacy manual row guards in table/key order.
3. Replays query expectations.
4. Checks optimistic row expectations and applies writes.

No shared-to-exclusive upgrade occurs after acquisition. PostgreSQL uses transaction-scoped advisory shared and
exclusive locks in the same query-guard namespace, separate from manual row and automatic absent-row guards.
Commit or rollback releases database locks; unit-of-work `clear()` removes queued guards and expectations.

## Evidence

- The existing coworking scenarios and competing-kernel races pass after removing all service-level manual guards.
- Two independent Postgres connections verify shared reader compatibility, reader/writer exclusion, protection of
  insertion/deletion/move scopes before query validation, and exclusive consolidation across mapped views.
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
- Query results are still checked before local writes. Coworking keeps its separate pending-write overlay.
- Email registration keeps the existing unguarded SQL expectation and database unique constraint.

The prototype supports the proposed interface and removes the need to remember guards in each booking action.
The remaining design questions are dependency completeness, handling writes without known old scopes, and the
granularity of derived keys. There is no performance conclusion without a benchmark.
