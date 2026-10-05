<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateEmbedded;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateStore;

#[State(table: 'orders')]
final readonly class OrderState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateColumn('status')]
        public string $orderStatus,
        #[StateColumn]
        public ?string $note,
    ) {}

    public function withOrderStatus(string $orderStatus): self
    {
        return new self($this->orderId, $orderStatus, $this->note);
    }
}

#[State(table: 'orders')]
final readonly class OrderIdentityState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
    ) {}
}

#[State(table: 'orders')]
final readonly class OrderUnwatchedState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateColumn('status', optimistic: false)]
        public string $status,
    ) {}
}

#[State(table: 'orders')]
final readonly class OrderStatusView
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateColumn('status')]
        public string $status,
    ) {}

    public function withStatus(string $status): self
    {
        return new self($this->orderId, $status);
    }
}

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
            ownerColumns: ['orderId' => 'order_id'],
        )]
        public array $lines,
    ) {}
}

final readonly class ShippingAddress
{
    public function __construct(
        public string $street,
        #[StateColumn('zip')]
        public string $postalCode,
    ) {}
}

final readonly class AcceptanceId
{
    public function __construct(
        public string $identityId,
        public string $termsId,
    ) {}
}

#[State(table: 'terms_acceptances')]
final readonly class TermsAcceptanceState
{
    public function __construct(
        #[StateId(columns: ['identityId' => 'identity_id', 'termsId' => 'terms_id'])]
        public AcceptanceId $acceptanceId,
        public string $acceptedAt,
    ) {}
}

#[State(table: 'default_terms_acceptances')]
final readonly class ConventionTermsAcceptanceState
{
    public function __construct(
        #[StateId(columns: ['termsId' => 'terms_id'])]
        public AcceptanceId $acceptanceId,
    ) {}
}

final readonly class CompositeOrderId
{
    public function __construct(
        public string $tenantId,
        public string $orderNumber,
    ) {}
}

final readonly class CompositeLineId
{
    public function __construct(
        public string $sku,
        public string $variant,
    ) {}
}

#[State(table: 'composite_order_lines')]
final readonly class CompositeLineState
{
    public function __construct(
        #[StateId(columns: ['sku' => 'line_sku', 'variant' => 'line_variant'])]
        public CompositeLineId $lineId,
        public int $quantity,
    ) {}
}

#[State(table: 'composite_orders')]
final readonly class CompositeOrderState
{
    /** @param list<CompositeLineState> $lines */
    public function __construct(
        #[StateId(columns: ['tenantId' => 'tenant_id', 'orderNumber' => 'order_number'])]
        public CompositeOrderId $orderId,
        #[StateCollection(itemClass: CompositeLineState::class)]
        public array $lines,
    ) {}
}

#[State(table: 'orders')]
final readonly class OrderWithShippingAddressState
{
    public function __construct(
        #[StateId(column: 'order_id')]
        public string $orderId,
        #[StateEmbedded(prefix: 'shipping_')]
        public ShippingAddress $shippingAddress,
    ) {}
}

#[State(table: 'counters')]
final readonly class CounterState
{
    public function __construct(#[StateId] public int $id, public int $count) {}
}

#[State(table: 'archived_counters')]
final readonly class ArchivedCounterState
{
    public function __construct(#[StateId] public int $id, public int $count) {}
}

#[State(table: 'a')]
final readonly class LeftBoundaryState
{
    public function __construct(#[StateId(column: 'bc')] public string $id, public string $value) {}
}

#[State(table: 'ab')]
final readonly class RightBoundaryState
{
    public function __construct(#[StateId(column: 'c')] public string $id, public string $value) {}
}
