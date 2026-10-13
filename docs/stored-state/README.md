# State Store

Mapper-backed object state loading, saving and deletion on top of `Ultra\MappedTable\MappedRowSet`.

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
- optimistic write expectations backed by `Ultra\MappedTable`
- support for multiple state classes over the same table
- declared query dependencies and automatic reader/writer guards

## Mapping and integration

- For attributes, identifiers, embedded values and collections, read [state mapping](mapping.md).
- For adapter plans and row-level reconciliation, read [MappedTable](mapped-table.md).
- For service discovery and container compilation, read [Symfony integration](symfony.md).

## Reads and observations

`load(StateClass::class, $id)` returns the unit of work's current view without registering expectations.
`exists(StateClass::class, $id)` checks only the root row's presence in that view, without expectations,
hydration or collection reads. It selects identifier columns and sees buffered inserts and deletions.
Use it for informational reads; use an observation when the result must protect a later decision.
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

## Deletion

`delete(StateClass::class, $id)` buffers deletion of the root row and empties the collections declared by that
state class for this owner. It accepts the same scalar and composite identifiers as `load()`, validates their
types, and reads neither the state nor its collections. It also works when stored values cannot hydrate that
state representation.

```php
if (null !== $stateStore->observe(OrderWithLinesState::class, 'order-123')) {
    $stateStore->delete(OrderWithLinesState::class, 'order-123');
}
```

Deletion retains all previously registered expectations, including those registered through another state view
of the same mapped row. Use `observe()`, or `load()` followed by `expect()`, before deletion to protect root values
and collection membership. Without these expectations, deletion only requires the root to exist at flush; changed
root values and collection members added before flush are deleted too. Collection observations guard membership,
as for saves, without comparing item values. A missing or concurrently deleted root causes `ConcurrencyException`.
When absence was already expected, deletion only checks that absence; it also cancels a buffered insertion made
after that expectation while retaining the check against competing creation.

`load()` and presence observations immediately see the buffered deletion, while storage changes only at flush.
Deletion supersedes buffered updates and collection saves for that owner. Repeating deletion is allowed. A later
`save()` of the deleted row, including through another view sharing its mapped key, throws `ConcurrencyException`
until `clear()`; deletion is the final mutation of that owner in this unit of work. `clear()` discards both deletion
and its expectations, allowing a fresh decision and save.

Flush acquires query guards and replays queries before applying any deletion. It checks and empties the deleted
owners' collection scopes before deleting root rows, then saves other owners' collections after their roots.
This ordering supports immediate foreign keys from collection rows to their owner without database cascades.
Use one surrounding transaction: a conflict on either the parent or its collections requires rollback of the
whole flush, followed by `clear()`, as described in [transactions](../transactions/README.md).

The supplied state class defines which collections are deleted. Collections exposed only by other state classes
and unrelated referencing tables are not discovered or cascaded by StateStore; use a representation declaring
the owned collections when deleting their parent. Deletions and collection cleanup participate in the declared
[query guards](query-guards.md), with the same requirements for observing a non-identifier scope before mutation.

## Query dependencies

For named guards declared from mapped-state dependencies, read the [query guard contract](query-guards.md). It owns declaration rules, read ordering, flush replay, exceptions and caller responsibilities.

For explicit SQL expectations and manually coordinated locks, read [manual queries and guards](manual-queries.md). These mechanisms supplement row expectations; the caller is responsible for enrolling every writer that affects the invariant.

## Test ownership and maintenance

The context's suites live in `src/StoredState/Tests`, including Symfony assembly from `StateStore_Bundle`.
Each file owns a guarantee; providers express alternative mappings, observation APIs or mutation kinds.

| Guarantee | Suite |
| --- | --- |
| State representations, embedded values and manual candidate keys preserve their physical mapping; deletion needs a valid identifier but no hydratable representation | [StateMappingTest](../../src/StoredState/Tests/StateMappingTest.php) |
| State views share buffered writes and deletions, preserve original expectations and start fresh after clear | [BufferedStateViewTest](../../src/StoredState/Tests/BufferedStateViewTest.php) |
| Value, presence and absence observations protect the dependency actually read, including across deletion and shared views | [StateObservationTest](../../src/StoredState/Tests/StateObservationTest.php) |
| Collection hydration, reconciliation and owner cleanup respect identities, membership expectations and atomic rollback with immediate foreign keys | [CollectionOwnershipTest](../../src/StoredState/Tests/CollectionOwnershipTest.php) |
| Recorded queries replay before writes, compare strictly and reject mutable expected values | [QueryReplayTest](../../src/StoredState/Tests/QueryReplayTest.php) |
| Guarded decision reads reject affected pending changes before executing or queuing a query | [QueryReadOrderingTest](../../src/StoredState/Tests/QueryReadOrderingTest.php) |
| Declared property and collection dependencies compile and enroll the relevant writers | [QueryDependencyTest](../../src/StoredState/Tests/QueryDependencyTest.php) |
| Flush consolidates and orders guards before replay and writes; clear removes pending work | [FlushCoordinationTest](../../src/StoredState/Tests/FlushCoordinationTest.php) |
| Cooperative guards share or exclude competing transactions and remain separate from persistence locks | [CooperativeLockingTest](../../src/StoredState/Tests/CooperativeLockingTest.php) |
| Symfony discovers root states and collection items, shares table views and assembles guards in dumped containers | [ContainerIntegrationTest](../../src/StoredState/Tests/ContainerIntegrationTest.php) |

The shared [fixture](../../src/StoredState/Tests/Support/StateStoreFixture.php) derives mappings from attributes
and opens explicit transaction boundaries; assertions stay in the suites. Memory and Postgres share owner-cleanup
and conflict cases. Postgres setup creates the needed tables in an isolated schema with immediate foreign keys;
contention cases retain two real connections. Row-plan mechanics, backend encodings and
intrinsic persistence locks belong to [MappedTable's suites](mapped-table.md#test-ownership-and-maintenance);
StateStore covers their composition with objects, collections and decision guards.

Use the shared [test-refactoring rules](../development.md#refactoring-tests) to maintain these guarantees
and compare coverage and mutations.
