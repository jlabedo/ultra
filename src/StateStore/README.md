# State Store

Mapper-backed object state loading and saving on top of `Ultra\MappedTable\MappedRowSet`.

## Purpose

`Ultra\StateStore\StateStore` lets you treat one mapped row as a typed readonly state object:

```php
$state = $stateStore->load(OrderState::class, 'order-123');
$changedState = $state->withOrderStatus('shipped');
$stateStore->expect($state);
$stateStore->save($changedState);
```

The package owns:

- attribute-based state-to-row mapping
- row hydration through `Ultra\Mapper\UltraNormalizer`
- optimistic save expectations backed by `Ultra\MappedTable`
- support for multiple state classes over the same table

## Mapping

Mark state classes with `#[Ultra\StateStore\Attribute\State(...)]` and one identifier property with `#[Ultra\StateStore\Attribute\StateId]`.

Other instance properties map by property name by default. Use `#[Ultra\StateStore\Attribute\StateColumn('column_name')]` when a property should target a different column name.

```php
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;

#[State(table: 'orders', idColumn: 'order_id')]
final readonly class OrderState
{
    public function __construct(
        #[StateId]
        public string $orderId,
        #[StateColumn('status')]
        public string $orderStatus,
        public ?string $note,
    ) {}
}
```

Collection properties can be persisted in a separate mapped row set with `#[Ultra\StateStore\Attribute\StateCollection(...)]`.
The parent state id is written to the configured owner column, and each collection item is normalized into one row keyed by its item id property.

```php
use Ultra\StateStore\Attribute\StateCollection;

#[State(table: 'order_lines', idColumn: 'line_id')]
final readonly class OrderLineState
{
    public function __construct(
        #[StateId]
        public string $lineId,
        #[StateColumn]
        public string $sku,
        #[StateColumn]
        public int $quantity,
    ) {}
}

#[State(table: 'orders', idColumn: 'order_id')]
final readonly class OrderWithLinesState
{
    /**
     * @param list<OrderLineState> $lines
     */
    public function __construct(
        #[StateId]
        public string $orderId,
        #[StateColumn('status')]
        public string $status,
        #[StateCollection(
            ownerColumn: 'order_id',
            itemClass: OrderLineState::class,
        )]
        public array $lines,
    ) {}
}
```

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

#[State(table: 'orders', idColumn: 'order_id')]
final readonly class OrderState
{
    public function __construct(
        #[StateId]
        public string $orderId,
        #[StateEmbedded(prefix: 'shipping_')]
        public Address $shippingAddress,
    ) {}
}
```

This maps `shippingAddress` to `shipping_street` and
`shipping_postalCode`. Embedded columns participate in the usual optimistic
expectations.

## Save Semantics

- `save($state)` buffers an upsert for the mapped row
- `expect($loadedState)` buffers optimistic expectations for that mapped row without writing anything by itself
- `expectMissing(StateClass::class, $id)` buffers an optimistic expectation that the mapped row is still absent
- a later `save(...)` against the same mapped row uses the buffered expectation automatically
- collection expectations use the loaded item id set to reject concurrent inserts or deletes in the collection
- buffered writes are committed later by flushing the underlying `MappedRowSet` unit of work

When several state classes target the same physical table, they share the same `MappedRowSet` unit of work but only read and write the columns each class maps.
