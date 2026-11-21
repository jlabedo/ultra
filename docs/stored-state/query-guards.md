# Query guards

Query guards protect decisions based on queries over mapped rows. Readers request shared guards. Affected
writers request exclusive guards for the same name and value. Queries run once during the decision and
are replayed under the guards inside the flush transaction.

## MappedTable query guards

A dependency identifies a row set definition, the physical column that supplies the guard value and the
physical columns read by the query. Register a MappedTable `GuardLockAdapter` using the same connection or
in-memory database as the row sets.

### Symfony attribute

Declare and inject a guard on a constructor parameter of an autowired service.

```php
use Ultra\MappedTable\Attribute\DefineQueryGuard;
use Ultra\MappedTable\QueryGuard;
use Ultra\MappedTable\QueryGuardDependency;

public function __construct(
    #[DefineQueryGuard('company_usage', [
        new QueryGuardDependency(BookingsDefinition::class, 'company_id', ['charged', 'start_at', 'end_at']),
    ])]
    private QueryGuard $companyUsage,
) {}
```

The guard is compiled for every coordinated writer even if the service is never instantiated.
Declare each name once. Other consumers can inject the registered service with
`#[Autowire(service: QueryGuard::class . '.company_usage')]`. A container that assembles writers must
register the guard declaration, either on an autowired service or in the extension configuration.

### Programmatic declaration

```php
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;

$guardDefinitions = [
    new QueryGuardDefinition('company_usage', [
        new QueryGuardDependency(BookingsDefinition::class, 'company_id', ['charged', 'start_at', 'end_at']),
        new QueryGuardDependency(BookingLinesDefinition::class, 'company_id'),
    ]),
];
```

Pass these declarations to `MappedTableExtension` in Symfony. In manual assembly, pass them as
`queryGuards` and the adapter as `guardLocks` to `MappedTableUnitOfWork`, then call
`queryGuard('company_usage')`.

### Dependencies and flush

Symfony validates guard names, definitions, columns and the lock adapter registration during compilation.
It emits dependency indexes by guard name and row set, watched column sets and ordered lock namespaces.
Each guard value comes from one non-null integer or string column. An omitted column list watches the full
row and an empty list watches membership and changes to the guard value. Inserting, deleting or replacing
a row always participates. A move acquires both the old and new guard values. An affected write on a
non-key value column needs an explicit previous-value expectation for an existing row. A blind upsert with
such a value is rejected. An explicit insert must supply the value.

Reconciliation, including a request to retain zero items, participates even when it has zero row mutations.
The owner key must provide the guard value for every dependency affected by that reconciliation. A column
outside the owner key is usable for direct row writes, with reconciliation rejected for that dependency.
An observation of membership alone does not request a writer guard.

Call `$guard->observe($value, $query)` before preparing writes affecting that value. It rejects conflicting
pending changes before invoking the callback, including changes from direct writes against the shared
row set. `lock($value)` requests a shared guard without reading or recording a query. Callbacks must read
storage on the flush connection and return repeatable scalars, null or arrays of these values. The coordinator
compares replay results with `===`. It acquires all guards before replay, consolidating shared and exclusive
requests for the same name and value to one exclusive lock. It then delegates persistence checks and writes
to the row-set adapters. PostgreSQL uses transaction-scoped advisory locks. Named guards are acquired in
name/value order, followed by manual row locks in table/key order. Use `READ COMMITTED` for replay to see
commits that precede lock acquisition.

Direct SQL, independently flushed row sets and other external writers need to follow the same protocol for
the invariant to hold. Acquisition is cooperative. The database remains responsible for its constraints.
The coordinator consumes the guards and query expectations on successful flush. The runner clears pending
work after rollback or when the decision itself fails.

## StateStore writes and errors

StateStore writes to the coordinated row sets used by MappedTable guards. A query that depends on a collection
declares the child row set and its owner column explicitly. If it also reads the parent row, add that
dependency. StateStore observations protect the row values and membership that the object view reads.

- `UnknownQueryGuardException` identifies a guard name missing from the coordinator.
- `PendingQueryChangesException` reports the guard, value, row set and key when an initial query would
  ignore an affected local mutation.
- `ConcurrencyException` reports a result that changed when the query replayed.

Use [manual queries and locks](manual-queries.md) for explicit SQL expectations or row locks.

## Tests

[ContainerIntegrationTest](../../src/StoredState/Tests/ContainerIntegrationTest.php) covers attribute
compilation, injected guards and shared writers. [QueryGuardTest](../../src/StoredState/Tests/MappedTable/QueryGuardTest.php)
covers plan inspection, lock ordering and replay. [CooperativeLockingTest](../../src/StoredState/Tests/CooperativeLockingTest.php)
exercises contention on real PostgreSQL connections. [QueryDependencyTest](../../src/StoredState/Tests/QueryDependencyTest.php)
covers writes and reconciliation prepared through StateStore.
