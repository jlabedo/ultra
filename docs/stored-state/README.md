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

When several state classes target the same physical table, they share the same `MappedRowSet` unit of work but only read and write the columns each class maps.

## Query dependencies

For named guards declared from mapped-state dependencies, read the [query guard contract](query-guards.md). It owns declaration rules, read ordering, flush replay, exceptions and caller responsibilities.

For explicit SQL expectations and manually coordinated locks, read [manual queries and guards](manual-queries.md). These mechanisms supplement row expectations; the caller is responsible for enrolling every writer that affects the invariant.
