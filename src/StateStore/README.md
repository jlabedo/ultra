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

## Mapping

Mark state classes with `#[Ultra\StateStore\Attribute\State(...)]` and one identifier property with `#[Ultra\StateStore\Attribute\StateId]`.

Other instance properties map by property name by default. Use `#[Ultra\StateStore\Attribute\StateColumn('column_name')]` when a property should target a different column name.

```php
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'orders')]
final readonly class OrderState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateColumn('status')]
        public string $orderStatus,
        public ?string $note,
    ) {}
}
```

Collection properties can be persisted in a separate mapped row set with `#[Ultra\StateStore\Attribute\StateCollection(...)]`.
StateStore maps collection items to keyed rows and delegates their reconciliation to `MappedRowSet`.
Saving a collection buffers its desired membership without reading storage. At flush, existing expectations
are checked before applying writes and removing other rows for that owner. Without expectations, the saved
collection takes precedence over rows added to storage before the flush deletion. Use `observe()`, or `load()`
followed by `expect()` before changes, to protect the original membership; `load()` alone registers no expectation.
The parent state key is combined with each item's own key. Owner columns inherit the parent's physical key mapping by default and can be overridden with `ownerColumns`. Collection item identities are relative to their owner; item classes are therefore not registered as independently loadable root states.

```php
use Ultra\StateStore\Attribute\StateCollection;

#[State(table: 'order_lines')]
final readonly class OrderLineState
{
    public function __construct(
        #[StateId(column: 'line_id')]
        public string $lineId,
        #[StateColumn]
        public string $sku,
        #[StateColumn]
        public int $quantity,
    ) {}
}

#[State(table: 'orders')]
final readonly class OrderWithLinesState
{
    /**
     * @param list<OrderLineState> $lines
     */
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateColumn('status')]
        public string $status,
        #[StateCollection(
            itemClass: OrderLineState::class,
        )]
        public array $lines,
    ) {}
}
```

An identifier remains one logical value even when it spans several columns:

```php
final readonly class AcceptanceId
{
    public function __construct(
        public string $identityId,
        public string $termsId,
    ) {}
}

#[State(table: 'terms_acceptances')]
final readonly class AcceptanceState
{
    public function __construct(
        #[StateId(columns: [
            'identityId' => 'identity_id',
            'termsId' => 'terms_id',
        ])]
        public AcceptanceId $id,
        public string $acceptedAt,
    ) {}
}
```

Mapper Compiler resolves whether the identifier normalizes to one scalar or a flat object shape. `column` and `columns` only override conventional column names. Composite components must normalize to `int|string`.

## Embedded value objects

`#[StateEmbedded]` keeps a value object in the state model while flattening its
properties into columns. The embedded object must be non-null and must not have
an identity, collection, or nested embedded mapping.

```php
use Ultra\StateStore\Attribute\StateEmbedded;

final readonly class Address
{
    public function __construct(
        public string $street,
        public string $postalCode,
    ) {}
}

#[State(table: 'orders')]
final readonly class OrderState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateEmbedded(prefix: 'shipping_')]
        public Address $shippingAddress,
    ) {}
}
```

This maps `shippingAddress` to `shipping_street` and
`shipping_postalCode`. Embedded columns participate in the usual optimistic
expectations.

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

## Query guards

Use `QueryGuard` for decisions based on queries over mapped rows. Declare dependencies on a repository's
constructor parameter; the bundle injects the named guard and registers its writer rules.

```php
use Ultra\StateStore\Attribute\DefineQueryGuard;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\QueryGuardDefinition;

public function __construct(
    #[DefineQueryGuard('company_usage', static function (QueryGuardDefinition $definition): void {
        $definition
            ->watch(Booking::class, keyBy: 'company_id')
            ->whenColumnsChange('charged', 'start_at', 'end_at');
    })]
    private QueryGuard $companyUsage,
) {}
```

`watch()` uses a registered root state and physical column names. `keyBy` identifies the guard's dynamic scope;
`whenColumnsChange()` identifies the query's dependencies. Insertions, deletions and scope changes always
participate. Omitting `whenColumnsChange()` includes all mutations. Keep this declaration consistent with the query.

```php
$used = $this->companyUsage->observe(
    $company->id,
    fn (): int => $this->usedMinutes($company, $month),
);
```

`observe($value, $query)` requests a shared guard, reads immediately and records the result for replay at flush.
`lock($value)` only requests the shared guard. Neither takes a database lock during the decision. Flush
automatically requests exclusive guards for affected writes, including actions that never call the query.
Observe the relevant states separately through `StateStore::observe()` to register optimistic row expectations.

An initial `observe()` rejects pending writes affecting the same declared scope with
`Ultra\StateStore\Exception\PendingQueryChangesException`, before executing the query. Decide before preparing
affected writes. Other scopes, unwatched columns and check-only expectations remain allowed. Flush replays earlier
observations before applying writes, without repeating this initial-read check.

Both methods reject unknown names or empty definitions with `Ultra\StateStore\Exception\UnknownQueryGuardException`.
Declarations must include watched states; constructing `QueryGuard` alone registers no writer rules. Dynamic
values are non-null integers or strings; collection-item tables and direct SQL writes are not automatically
enrolled. Callbacks return scalars, null or arrays, compared strictly; a changed result raises
`Ultra\UnitOfWork\Exception\ConcurrencyException` at flush.

See the [query guard contract](QUERY_GUARDS.md) for the flush protocol, exceptions, manual construction and
developer responsibilities, and the [bundle configuration](../StateStore_Bundle/README.md) for constructor
injection. The [direct coworking variant](../../examples/coworking/src/Booking/StateStoreDirect) validates all
weekly slots and the total requested minutes per month before buffering bookings, with no repository overlay.

## Manual queries and deferred guards

For rules expressed as SQL queries, record the guards and the observed results during the decision:

```php
$unitOfWorkRunner->run(function () use ($stateStore, $repository, $company, $room, $slot, $booking): void {
    $stateStore->expect($company);
    $stateStore->expect($room);
    $stateStore->lock($company);
    $stateStore->lock($room);

    $used = $stateStore->observeQuery(
        fn (): int => $repository->usedMinutes($company, $slot->month()),
        name: 'monthly usage',
    );
    // Validate the quota and the other business rules, then prepare the write.
    $stateStore->expectMissing($booking::class, $booking->id);
    $stateStore->save($booking);
});
```

`observeQuery($query, ?string $name = null)` executes the callback immediately, registers its result as the expected
value, and returns it. `expectQuery($query, $expected, ?string $name = null)` registers an expectation without
executing the callback. Expected values must be scalars, null or arrays of these values. Comparison uses `===`:
cast database numeric values, normalize timestamps, and sort unordered query results in the callback. A mismatch
throws `Ultra\UnitOfWork\Exception\ConcurrencyException`; the optional name identifies the failed expectation.

`lock($state)` records a guard in `StateTableRegistry` for the state's physical table and mapped key, including
composite keys and missing rows. Registering a guard takes no database lock. It neither replaces `expect()` nor protects against writers that
do not request the same guard. The developer chooses the guards and must use them in every action affecting the
invariant: booking, cancellation, reschedule and bulk closure, for example.

The `StateTableRegistry` flush has three phases, inside **one transaction**:

1. Derive any compiled query guards, acquire them in name/value order, then acquire requested manual advisory locks
   in physical table/key order.
2. Replay all query expectations before applying any row-set plan.
3. Check the optimistic row expectations and apply the existing write plans.

Configure a dedicated `GuardLockAdapter` service when using manual or compiled query guards. For Postgres:

```yaml
Ultra\StateStore\GuardLockAdapter:
    class: Ultra\StateStore\PostgresGuardLockAdapter
    arguments:
        $connection: '@doctrine.dbal.default_connection'
```

Use `InMemoryGuardLockAdapter` with the same `InMemoryRowDatabase` as the mapped adapters for in-memory work.
`MappedRowSet` contains only row plans and optimistic expectations; cooperative guards are owned by the registry
and acquired through the dedicated adapter. Guard-only work does not need to create a mapped row set.

With `TransactionalUnitOfWorkRunner`, application work runs before the transaction is opened. Configure its
`TransactionManager` for the same connection used by the mapped adapters, guard adapter and query callbacks. The runner commits
or rolls back the entire flush and clears the queues on success or failure. Calling `StateTableRegistry::flush()`
directly requires the caller to provide that transaction and call `clear()` when the unit of work ends. Query-only
and guard-only work also counts as pending. This mechanism uses no `SERIALIZABLE` isolation and does not prelock
every row that will be written. Ordered manual guards do not remove every possible database deadlock, particularly
when other writers or callbacks acquire additional locks in a different order.

Callbacks must be read-only, repeatable, and use the flush connection. Capture stable parameters; they must read
committed database data, not `StateStore::load()`'s buffered view. Query results are compared **before this unit of
work's writes**. Account for pending changes separately when deciding, without adding them to the SQL expectation.
The unguarded API has no dependency metadata, so it cannot detect when these results ignore affected local writes.
