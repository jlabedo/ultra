<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryMappedRowSetAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateEmbedded;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

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

/**
 * @internal
 *
 * @coversNothing
 */
final class StateStoreTest extends TestCase
{
    public function test_composite_identifier_uses_default_column_names_with_partial_overrides(): void
    {
        $analyzer = new NormalizationShapeAnalyzer();
        $factory = new StateMetadataFactory([
            ConventionTermsAcceptanceState::class => $analyzer->forProperty(
                (new \ReflectionClass(ConventionTermsAcceptanceState::class))->getProperty('acceptanceId'),
            ),
        ]);

        self::assertSame([
            'identityId' => 'identityId',
            'termsId' => 'terms_id',
        ], $factory->create(ConventionTermsAcceptanceState::class)->idPropertyColumns);
    }

    public function test_composite_identifier_round_trips_and_addresses_one_physical_row(): void
    {
        $database = new InMemoryRowDatabase();
        $analyzer = new NormalizationShapeAnalyzer();
        $idProperty = (new \ReflectionClass(TermsAcceptanceState::class))->getProperty('acceptanceId');
        $factory = new StateMetadataFactory([
            TermsAcceptanceState::class => $analyzer->forProperty($idProperty),
        ]);
        $metadata = $factory->create(TermsAcceptanceState::class);
        $table = new MappedRowSet(new InMemoryMappedRowSetAdapter(
            $database,
            'terms_acceptances',
            ['identity_id', 'terms_id'],
        ));
        $registry = new StateTableRegistry([$metadata->tableKey => $table]);
        $store = new StateStore(
            new StateMetadataRegistry([TermsAcceptanceState::class => $metadata->toArray()]),
            $registry,
            new UltraNormalizer(),
        );

        $id = new AcceptanceId('identity-1', 'terms-2026');
        $store->expectMissing(TermsAcceptanceState::class, $id);
        $store->save(new TermsAcceptanceState($id, '2026-07-10T10:00:00Z'));
        $this->flushRegistryInTransaction($database, $registry);

        self::assertSame([
            'identity_id' => 'identity-1',
            'terms_id' => 'terms-2026',
            'acceptedAt' => '2026-07-10T10:00:00Z',
        ], $database->rowByKey('terms_acceptances', new \Ultra\MappedTable\MappedKey([
            'identity_id' => 'identity-1',
            'terms_id' => 'terms-2026',
        ])));

        $loaded = $store->load(TermsAcceptanceState::class, new AcceptanceId('identity-1', 'terms-2026'));
        self::assertInstanceOf(TermsAcceptanceState::class, $loaded);
        self::assertSame('identity-1', $loaded->acceptanceId->identityId);
        self::assertSame('terms-2026', $loaded->acceptanceId->termsId);
    }

    public function test_collection_combines_composite_owner_and_item_keys(): void
    {
        $database = new InMemoryRowDatabase();
        $analyzer = new NormalizationShapeAnalyzer();
        $shapes = [];
        foreach ([CompositeOrderState::class => 'orderId', CompositeLineState::class => 'lineId'] as $class => $property) {
            $shapes[$class] = $analyzer->forProperty((new \ReflectionClass($class))->getProperty($property));
        }
        $factory = new StateMetadataFactory($shapes);
        $metadata = $factory->create(CompositeOrderState::class);
        $table = new MappedRowSet(new InMemoryMappedRowSetAdapter(
            $database,
            'composite_orders',
            ['tenant_id', 'order_number'],
        ));
        $registry = new StateTableRegistry(
            [$metadata->tableKey => $table],
            new \Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory($database),
        );
        $store = new StateStore(
            new StateMetadataRegistry([CompositeOrderState::class => $metadata->toArray()]),
            $registry,
            new UltraNormalizer(),
        );

        $id = new CompositeOrderId('tenant-1', 'order-42');
        $store->save(new CompositeOrderState($id, [
            new CompositeLineState(new CompositeLineId('sku-1', 'blue'), 2),
        ]));
        $this->flushRegistryInTransaction($database, $registry);

        $lineKey = new \Ultra\MappedTable\MappedKey([
            'tenant_id' => 'tenant-1',
            'order_number' => 'order-42',
            'line_sku' => 'sku-1',
            'line_variant' => 'blue',
        ]);
        self::assertSame(['quantity' => 2], $database->rowByKey('composite_order_lines', $lineKey, ['quantity']));

        $loaded = $store->load(CompositeOrderState::class, new CompositeOrderId('tenant-1', 'order-42'));
        self::assertInstanceOf(CompositeOrderState::class, $loaded);
        self::assertCount(1, $loaded->lines);
        self::assertSame('blue', $loaded->lines[0]->lineId->variant);
    }

    public function test_load_hydrates_readonly_state_from_mapped_row(): void
    {
        [$database, $table, $store] = $this->newStore([OrderState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'pending',
            'note' => 'draft',
        ]);

        $state = $store->load(OrderState::class, 'order-123');

        self::assertInstanceOf(OrderState::class, $state);
        self::assertSame('order-123', $state->orderId);
        self::assertSame('pending', $state->orderStatus);
        self::assertSame('draft', $state->note);
        self::assertSame([
            'status' => 'pending',
            'note' => 'draft',
        ], $table->row('order-123', ['status', 'note']));
    }

    public function test_save_upserts_new_readonly_state(): void
    {
        [$database, $table, $store] = $this->newStore([OrderState::class]);

        $store->save(new OrderState('order-123', 'pending', 'draft'));

        $loaded = $store->load(OrderState::class, 'order-123');
        self::assertInstanceOf(OrderState::class, $loaded);
        self::assertSame('pending', $loaded->orderStatus);

        self::assertSame([
            'status' => 'pending',
            'note' => 'draft',
        ], $table->row('order-123', ['status', 'note']));

        $this->flushInTransaction($database, $table);

        self::assertSame([
            'status' => 'pending',
            'note' => 'draft',
        ], $database->row('orders', 'order_id', 'order-123', ['status', 'note']));
    }

    public function test_expected_save_rejects_stale_mapped_state(): void
    {
        [$database, $table, $store] = $this->newStore([OrderState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'pending',
            'note' => 'draft',
        ]);

        $loaded = $store->load(OrderState::class, 'order-123');
        self::assertInstanceOf(OrderState::class, $loaded);

        $database->writeRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'paid',
            'note' => 'draft',
        ]);

        $store->expect($loaded);
        $store->save($loaded->withOrderStatus('shipped'));

        $this->expectException(ConcurrencyException::class);

        $this->flushInTransaction($database, $table);
    }

    public function test_expectation_can_be_buffered_without_saving(): void
    {
        [$database, $table, $store] = $this->newStore([OrderState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'pending',
            'note' => 'draft',
        ]);

        $loaded = $store->load(OrderState::class, 'order-123');
        self::assertInstanceOf(OrderState::class, $loaded);

        $store->expect($loaded);
        $this->flushInTransaction($database, $table);

        self::assertSame([
            'status' => 'pending',
            'note' => 'draft',
        ], $database->row('orders', 'order_id', 'order-123', ['status', 'note']));
    }

    public function test_expectation_from_one_state_class_can_guard_save_of_another_on_same_row(): void
    {
        [$database, $table, $store] = $this->newStore([OrderState::class, OrderStatusView::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'pending',
            'note' => 'draft',
        ]);

        $view = $store->load(OrderStatusView::class, 'order-123');
        self::assertInstanceOf(OrderStatusView::class, $view);

        $store->expect($view);
        $store->save(new OrderState('order-123', 'shipped', 'confirmed'));
        $this->flushInTransaction($database, $table);

        self::assertSame([
            'status' => 'shipped',
            'note' => 'confirmed',
        ], $database->row('orders', 'order_id', 'order-123', ['status', 'note']));
    }

    public function test_load_returns_null_for_missing_state(): void
    {
        [, , $store] = $this->newStore([OrderState::class]);

        self::assertNull($store->load(OrderState::class, 'missing-order'));
    }

    public function test_embedded_value_object_is_hydrated_from_flat_columns(): void
    {
        [$database, , $store] = $this->newStore([OrderWithShippingAddressState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'shipping_street' => '1 Main Street',
            'shipping_zip' => '75001',
        ]);

        $state = $store->load(OrderWithShippingAddressState::class, 'order-123');

        self::assertEquals(new OrderWithShippingAddressState(
            'order-123',
            new ShippingAddress('1 Main Street', '75001'),
        ), $state);
    }

    public function test_embedded_value_object_is_saved_as_flat_columns_and_participates_in_optimistic_expectations(): void
    {
        [$database, $table, $store] = $this->newStore([OrderWithShippingAddressState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'shipping_street' => '1 Main Street',
            'shipping_zip' => '75001',
        ]);

        $loaded = $store->load(OrderWithShippingAddressState::class, 'order-123');
        self::assertInstanceOf(OrderWithShippingAddressState::class, $loaded);

        $store->expect($loaded);
        $store->save(new OrderWithShippingAddressState(
            'order-123',
            new ShippingAddress('2 Main Street', '75001'),
        ));

        self::assertSame([
            'shipping_street' => '2 Main Street',
            'shipping_zip' => '75001',
        ], $table->row('order-123', ['shipping_street', 'shipping_zip']));

        $database->writeRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'shipping_street' => '1 Main Street',
            'shipping_zip' => '75002',
        ]);

        $this->expectException(ConcurrencyException::class);

        $this->flushInTransaction($database, $table);
    }

    public function test_expect_missing_turns_later_save_into_insert(): void
    {
        [$database, $table, $store] = $this->newStore([OrderState::class]);

        $store->expectMissing(OrderState::class, 'order-123');
        $store->save(new OrderState('order-123', 'pending', 'draft'));

        $this->flushInTransaction($database, $table);

        self::assertSame([
            'status' => 'pending',
            'note' => 'draft',
        ], $database->row('orders', 'order_id', 'order-123', ['status', 'note']));
    }

    public function test_expect_missing_without_save_does_not_write_anything(): void
    {
        [$database, $table, $store] = $this->newStore([OrderState::class]);

        $store->expectMissing(OrderState::class, 'order-123');
        $this->flushInTransaction($database, $table);

        self::assertFalse($database->row('orders', 'order_id', 'order-123', ['status', 'note']));
    }

    public function test_expect_missing_rejects_concurrent_insert(): void
    {
        [$database, $table, $store] = $this->newStore([OrderState::class]);

        $store->expectMissing(OrderState::class, 'order-123');
        $store->save(new OrderState('order-123', 'pending', 'draft'));

        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'paid',
            'note' => 'already-there',
        ]);

        $this->expectException(ConcurrencyException::class);

        $this->flushInTransaction($database, $table);
    }

    public function test_load_hydrates_state_collection_from_scoped_row_set(): void
    {
        [$database, , $store] = $this->newStore([OrderWithLinesState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'pending',
        ]);
        $database->seedRow('order_lines', 'line_id', [
            'order_id' => 'order-123',
            'line_id' => 'line-2',
            'sku' => 'SKU-2',
            'quantity' => 1,
        ]);
        $database->seedRow('order_lines', 'line_id', [
            'order_id' => 'order-123',
            'line_id' => 'line-1',
            'sku' => 'SKU-1',
            'quantity' => 2,
        ]);
        $database->seedRow('order_lines', 'line_id', [
            'order_id' => 'other-order',
            'line_id' => 'line-3',
            'sku' => 'SKU-3',
            'quantity' => 9,
        ]);

        $state = $store->load(OrderWithLinesState::class, 'order-123');

        self::assertInstanceOf(OrderWithLinesState::class, $state);
        self::assertSame('pending', $state->status);
        self::assertContainsOnlyInstancesOf(OrderLineState::class, $state->lines);
        self::assertSame(['line-1', 'line-2'], array_map(
            static fn (OrderLineState $line): string => $line->lineId,
            $state->lines,
        ));
    }

    public function test_save_persists_collection_rows_and_deletes_removed_rows(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderWithLinesState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'pending',
        ]);
        $database->seedRow('order_lines', 'line_id', [
            'order_id' => 'order-123',
            'line_id' => 'line-1',
            'sku' => 'OLD',
            'quantity' => 1,
        ]);
        $database->seedRow('order_lines', 'line_id', [
            'order_id' => 'order-123',
            'line_id' => 'line-2',
            'sku' => 'REMOVED',
            'quantity' => 1,
        ]);

        $store->save(new OrderWithLinesState('order-123', 'paid', [
            new OrderLineState('line-1', 'SKU-1', 3),
            new OrderLineState('line-3', 'SKU-3', 1),
        ]));

        $this->flushRegistryInTransaction($database, $registry);

        self::assertSame([
            'order_id' => 'order-123',
            'sku' => 'SKU-1',
            'quantity' => 3,
        ], $database->row('order_lines', 'line_id', 'line-1', ['order_id', 'sku', 'quantity'], ['order_id' => 'order-123']));
        self::assertFalse($database->row('order_lines', 'line_id', 'line-2'));
        self::assertSame([
            'order_id' => 'order-123',
            'sku' => 'SKU-3',
            'quantity' => 1,
        ], $database->row('order_lines', 'line_id', 'line-3', ['order_id', 'sku', 'quantity'], ['order_id' => 'order-123']));
    }

    public function test_expect_rejects_collection_insert_or_delete(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderWithLinesState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'pending',
        ]);
        $database->seedRow('order_lines', 'line_id', [
            'order_id' => 'order-123',
            'line_id' => 'line-1',
            'sku' => 'SKU-1',
            'quantity' => 1,
        ]);

        $loaded = $store->load(OrderWithLinesState::class, 'order-123');
        self::assertInstanceOf(OrderWithLinesState::class, $loaded);
        $store->expect($loaded);
        $database->seedRow('order_lines', 'line_id', [
            'order_id' => 'order-123',
            'line_id' => 'line-2',
            'sku' => 'SKU-2',
            'quantity' => 1,
        ]);
        $store->save($loaded);

        $this->expectException(ConcurrencyException::class);
        $this->flushRegistryInTransaction($database, $registry);
    }

    /**
     * @param list<class-string> $stateClasses
     *
     * @return array{InMemoryRowDatabase, MappedRowSet, StateStore, StateTableRegistry}
     */
    private function newStore(array $stateClasses): array
    {
        $database = new InMemoryRowDatabase();
        $table = new MappedRowSet(new InMemoryMappedRowSetAdapter($database, 'orders', ['order_id']));
        $adapterFactory = new \Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory($database);
        $analyzer = new NormalizationShapeAnalyzer();
        $identityShapes = [];
        foreach (array_unique([...$stateClasses, OrderLineState::class]) as $stateClass) {
            foreach ((new \ReflectionClass($stateClass))->getProperties() as $property) {
                if ([] !== $property->getAttributes(StateId::class)) {
                    $identityShapes[$stateClass] = $analyzer->forProperty($property);
                    break;
                }
            }
        }
        $factory = new StateMetadataFactory($identityShapes);

        $metadata = [];
        foreach ($stateClasses as $stateClass) {
            $resolvedMetadata = $factory->create($stateClass);
            $metadata[$stateClass] = $resolvedMetadata->toArray();
        }

        $registry = new StateTableRegistry(['orders#order_id' => $table], $adapterFactory);
        $store = new StateStore(
            new StateMetadataRegistry($metadata),
            $registry,
            new UltraNormalizer(),
        );

        return [$database, $table, $store, $registry];
    }

    private function flushInTransaction(InMemoryRowDatabase $database, MappedRowSet $table): void
    {
        $database->beginTransaction();

        try {
            $table->flush();
            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }

            throw $exception;
        }
    }

    private function flushRegistryInTransaction(InMemoryRowDatabase $database, StateTableRegistry $registry): void
    {
        $database->beginTransaction();

        try {
            $registry->flush();
            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }

            throw $exception;
        }
    }
}
