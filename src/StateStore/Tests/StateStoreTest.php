<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedTable;
use Ultra\Mapper\UltraNormalizer;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\StateNotFoundException;
use Ultra\StateStore\StateMetadataFactory;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

#[State(table: 'orders', idColumn: 'order_id')]
final readonly class OrderState
{
    public function __construct(
        #[StateId]
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

#[State(table: 'orders', idColumn: 'order_id')]
final readonly class OrderStatusView
{
    public function __construct(
        #[StateId]
        public string $orderId,
        #[StateColumn('status')]
        public string $status,
    ) {}

    public function withStatus(string $status): self
    {
        return new self($this->orderId, $status);
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class StateStoreTest extends TestCase
{
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

    public function test_load_throws_for_missing_state(): void
    {
        [, , $store] = $this->newStore([OrderState::class]);

        $this->expectException(StateNotFoundException::class);

        $store->load(OrderState::class, 'missing-order');
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

    /**
     * @param list<class-string> $stateClasses
     *
     * @return array{InMemoryRowDatabase, MappedTable, StateStore}
     */
    private function newStore(array $stateClasses): array
    {
        $database = new InMemoryRowDatabase();
        $table = new MappedTable(new InMemoryMappedTableAdapter($database, 'orders', 'order_id'));
        $factory = new StateMetadataFactory();

        $metadata = [];
        foreach ($stateClasses as $stateClass) {
            $resolvedMetadata = $factory->create($stateClass);
            $metadata[$stateClass] = $resolvedMetadata->toArray();
        }

        $store = new StateStore(
            new StateMetadataRegistry($metadata),
            new StateTableRegistry(['orders#order_id' => $table]),
            new UltraNormalizer(),
        );

        return [$database, $table, $store];
    }

    private function flushInTransaction(InMemoryRowDatabase $database, MappedTable $table): void
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
}
