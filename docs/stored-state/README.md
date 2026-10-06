# State Store

Mapper-backed object state loading and saving on top of `Ultra\MappedTable\MappedRowSet`.

## Purpose

`Ultra\StateStore\StateStore` lets you treat one mapped row as a typed readonly state object:

```php
$state = $stateStore->observe(OrderState::class, 'order-123');
$changedState = $state->withOrderStatus('shipped');
$stateStore->save($changedState);
```

The package owns:

- attribute-based state-to-row mapping
- row hydration through `Ultra\Mapper\UltraNormalizer`
- optimistic save expectations backed by `Ultra\MappedTable`
- support for multiple state classes over the same table
- declared query dependencies and automatic reader/writer guards

## Mapping and integration

- For attributes, identifiers, embedded values and collections, read [state mapping](mapping.md).
- For adapter plans and row-level reconciliation, read [MappedTable](mapped-table.md).
- For service discovery and container compilation, read [Symfony integration](symfony.md).

## Reads and observations

`load(StateClass::class, $id)` returns the unit of work's current view without registering expectations.
`observe(StateClass::class, $id)` returns the same typed state or null and automatically registers:

- `expect($state)` when the state exists: its row must remain present, its optimistic columns must match, and
  collection membership is checked with the usual collection expectations;
- `expectMissing(StateClass::class, $id)` when it is absent: a later save is an insert, and a competing creation
  causes a concurrency conflict.

`observeExists(StateClass::class, $id)` returns a boolean and observes only the root row's
presence or absence. It selects the identifier columns, without hydrating the state or loading
its collections. Column changes and collection changes do not invalidate this observation;
a competing deletion or creation does. It sees buffered writes and retains earlier expectations.
Use it for references whose existence is the only dependency. Use `observe()` when the decision
also depends on values or collection membership. Existing stronger expectations are not removed
by a subsequent `observeExists()` call.

The presence check also applies to states containing only an identifier or having no optimistic columns.
Columns marked `optimistic: false` remain excluded from value comparisons. Scalar and composite identifiers use
the same mapping and validation as `load()`.

Observation acquires no database lock during the read and writes nothing by itself. Its expectations count as
pending work, are checked at flush even without a save, and are discarded by `clear()`. Repeated observations see
buffered inserts and updates while retaining the original expectations against storage. The caller still handles
a null result and chooses any manual guards required for set-based rules.

## Save Semantics

- `save($state)` buffers an upsert for the mapped row
- `expect($loadedState)` buffers optimistic expectations for that mapped row without writing anything by itself; they are checked at flush even if the state is never saved, so a state that is only read still guards the decision
- `expectMissing(StateClass::class, $id)` buffers an optimistic expectation that the mapped row is still absent, also checked at flush
- a later `save(...)` against the same mapped row uses the buffered expectation automatically
- collection expectations use the loaded item id set to reject concurrent inserts or deletes in the collection
- buffered writes are committed later by flushing `StateTableRegistry`, which coordinates the underlying `MappedRowSet` units of work

State classes targeting the same physical table and complete key mapping share a `MappedRowSet` unit of work,
but only read and write the columns each class maps. See [mapping](mapping.md) for manually assembled row sets
using different candidate keys.

## Query dependencies

For named guards declared from mapped-state dependencies, read the [query guard contract](query-guards.md). It owns declaration rules, read ordering, flush replay, exceptions and caller responsibilities.

For explicit SQL expectations and manually coordinated locks, read [manual queries and guards](manual-queries.md). These mechanisms supplement row expectations; the caller is responsible for enrolling every writer that affects the invariant.

## Test ownership and maintenance

The context's suites live in `src/StateStore/Tests`, including Symfony assembly from `StateStore_Bundle`.
Each file owns a guarantee; providers express alternative mappings, observation APIs or mutation kinds.

| Guarantee | Suite |
| --- | --- |
| State representations, embedded values and manual candidate keys preserve their physical mapping | [StateMappingTest](../../src/StateStore/Tests/StateMappingTest.php) |
| State views read buffered changes and retain the original storage expectations | [BufferedStateViewTest](../../src/StateStore/Tests/BufferedStateViewTest.php) |
| Value, presence and absence observations protect the dependency actually read, with or without a save | [StateObservationTest](../../src/StateStore/Tests/StateObservationTest.php) |
| Collection hydration, reconciliation and expectations respect owner and item identities | [CollectionOwnershipTest](../../src/StateStore/Tests/CollectionOwnershipTest.php) |
| Recorded queries replay before writes, compare strictly and reject mutable expected values | [QueryReplayTest](../../src/StateStore/Tests/QueryReplayTest.php) |
| Guarded decision reads reject affected pending changes before executing or queuing a query | [QueryReadOrderingTest](../../src/StateStore/Tests/QueryReadOrderingTest.php) |
| Declared property and collection dependencies compile and enroll the relevant writers | [QueryDependencyTest](../../src/StateStore/Tests/QueryDependencyTest.php) |
| Flush consolidates and orders guards before replay and writes; clear removes pending work | [FlushCoordinationTest](../../src/StateStore/Tests/FlushCoordinationTest.php) |
| Cooperative guards share or exclude competing transactions and remain separate from persistence locks | [CooperativeLockingTest](../../src/StateStore/Tests/CooperativeLockingTest.php) |
| Symfony discovers root states and collection items, shares table views and assembles guards in dumped containers | [ContainerIntegrationTest](../../src/StateStore/Tests/ContainerIntegrationTest.php) |

The shared fixture derives mappings from attributes and opens explicit transaction boundaries; assertions stay
in the suites. Postgres contention cases retain two real connections. Row-plan mechanics, backend encodings and
intrinsic persistence locks belong to [MappedTable's suites](mapped-table.md#test-ownership-and-maintenance);
StateStore covers their composition with objects, collections and decision guards.

Use the shared [test-refactoring rules](../development.md#refactoring-tests) to maintain these guarantees
and compare coverage and mutations.
