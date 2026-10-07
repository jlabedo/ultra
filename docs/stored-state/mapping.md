# Stored-state mapping

Read when changing state attributes, identifiers, embedded values or collections. For load, observation and save semantics, use the [state-store contract](README.md).

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
For owner deletion and collection cleanup, use the [deletion contract](README.md#deletion).
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

The [Symfony integration](symfony.md#services-and-state-discovery) requires one complete key mapping per
physical table. Manually assembled metadata and row sets can address a table through different unique candidate
keys, such as `contact_id` and `email`. Those row sets have separate buffers; each adapter must use its metadata's
complete key columns, backed by the corresponding unique constraint in PostgreSQL.

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
