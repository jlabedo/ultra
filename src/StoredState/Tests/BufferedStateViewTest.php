<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final class BufferedStateViewTest extends StateStoreTestCase
{
    public function test_a_row_loaded_again_in_the_same_unit_of_work_reads_its_own_writes(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'pending',
            'note' => 'draft',
        ]);

        $first = $store->load(OrderState::class, 'order-123');
        $store->expect($first);
        $store->save($first->withOrderStatus('paid'));

        $second = $store->load(OrderState::class, 'order-123');
        static::assertSame('paid', $second?->orderStatus);
        $store->expect($second);
        $store->save($second->withOrderStatus('shipped'));
        $this->flush($database, $registry);

        static::assertSame(['status' => 'shipped'], $database->row('orders', 'order_id', 'order-123', ['status']));
    }

    public function test_a_collection_loaded_again_in_the_same_unit_of_work_reads_its_own_writes(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderWithLinesState::class]);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'pending']);
        $database->seedRow('order_lines', 'line_id', [
            'order_id' => 'order-123',
            'line_id' => 'line-1',
            'sku' => 'SKU-1',
            'quantity' => 1,
        ]);

        $first = $store->load(OrderWithLinesState::class, 'order-123');
        $store->expect($first);
        $store->save(new OrderWithLinesState('order-123', 'pending', [
            ...$first->lines,
            new OrderLineState('line-2', 'SKU-2', 2),
        ]));

        $second = $store->load(OrderWithLinesState::class, 'order-123');
        static::assertCount(2, $second->lines ?? []);
        $store->expect($second);
        $store->save(new OrderWithLinesState('order-123', 'pending', [
            ...$second->lines,
            new OrderLineState('line-3', 'SKU-3', 3),
        ]));
        $this->flush($database, $registry);

        $lines = $store->load(OrderWithLinesState::class, 'order-123')->lines ?? [];
        static::assertSame(
            ['line-1', 'line-2', 'line-3'],
            array_map(static fn(OrderLineState $line): string => $line->lineId, $lines),
        );
    }

    public function test_expectations_registered_before_own_writes_still_reject_a_concurrent_change(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class]);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'pending', 'note' => 'draft']);

        $first = $store->load(OrderState::class, 'order-123');
        $store->expect($first);
        $store->save($first->withOrderStatus('paid'));
        $second = $store->load(OrderState::class, 'order-123');
        $store->expect($second);
        $database->writeRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'cancelled',
            'note' => 'draft',
        ]);

        $this->expectException(ConcurrencyException::class);
        $this->flush($database, $registry);
    }

    public function test_state_classes_sharing_a_row_see_each_other_writes(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class, OrderStatusView::class]);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'pending', 'note' => 'draft']);

        $staleView = $store->load(OrderStatusView::class, 'order-123');
        $order = $store->load(OrderState::class, 'order-123');
        $store->expect($order);
        $store->save($order->withOrderStatus('paid'));

        try {
            $store->expect($staleView);
            static::fail('A view loaded before the write must be rejected.');
        } catch (ConcurrencyException) {
        }

        $freshView = $store->load(OrderStatusView::class, 'order-123');
        $store->expect($freshView);
        $store->save($freshView->withStatus('shipped'));
        $this->flush($database, $registry);

        static::assertSame(
            ['status' => 'shipped', 'note' => 'draft'],
            $database->row('orders', 'order_id', 'order-123', ['status', 'note']),
        );
    }

    public function test_after_clear_a_stale_state_is_checked_against_storage(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class]);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'pending', 'note' => 'draft']);

        $stale = $store->load(OrderState::class, 'order-123');
        $store->expect($stale);
        $registry->clear();
        $database->writeRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'paid', 'note' => 'draft']);

        $store->expect($stale);
        $store->save($stale->withOrderStatus('shipped'));

        $this->expectException(ConcurrencyException::class);
        $this->flush($database, $registry);
    }

    public function test_observe_reads_own_inserts_and_updates_without_losing_the_original_expectation(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class, OrderStatusView::class]);
        static::assertNull($store->observe(OrderState::class, 'order-123'));
        $store->save(new OrderState('order-123', 'pending', null));
        $created = $store->observe(OrderState::class, 'order-123');
        static::assertSame('pending', $created->orderStatus);
        $store->save($created->withOrderStatus('paid'));
        $view = $store->observe(OrderStatusView::class, 'order-123');
        static::assertSame('paid', $view->status);
        $store->save($view->withStatus('shipped'));
        $this->flush($database, $registry);
        $registry->clear();
        static::assertSame('shipped', $store->load(OrderState::class, 'order-123')->orderStatus);

        $observed = $store->observe(OrderState::class, 'order-123');
        $store->save($observed->withOrderStatus('delivered'));
        static::assertSame('delivered', $store->observe(OrderState::class, 'order-123')->orderStatus);
        $database->writeRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'cancelled', 'note' => null]);
        $this->expectException(ConcurrencyException::class);
        $this->flush($database, $registry);
    }

    #[DataProvider('backends')]
    public function test_clear_reopens_a_deleted_owner_shared_by_other_state_views(string $backend): void
    {
        $fixture = $this->stateFixture($backend, [OrderWithLinesState::class, OrderStatusView::class]);
        $state = new OrderWithLinesState('one', 'pending', [new OrderLineState('line', 'SKU', 1)]);
        $fixture->store->save($state);
        $fixture->flush();
        $fixture->registry->clear();
        $fixture->store->observe(OrderWithLinesState::class, 'one');
        $fixture->store->delete(OrderWithLinesState::class, 'one');
        try {
            $fixture->store->save(new OrderStatusView('one', 'paid'));
            static::fail('A deleted owner cannot be saved through another view.');
        } catch (ConcurrencyException $exception) {
            static::assertStringContainsString('deleted in this unit of work', $exception->getMessage());
        }
        $fixture->registry->clear();

        static::assertFalse($fixture->registry->hasPendingChanges());
        static::assertEquals($state, $fixture->store->load(OrderWithLinesState::class, 'one'));
        $fixture->store->save(new OrderWithLinesState('one', 'paid', $state->lines));
        $fixture->flush();
        $fixture->registry->clear();
        static::assertSame('paid', $fixture->store->load(OrderWithLinesState::class, 'one')->status);
    }

    #[DataProvider('backends')]
    public function test_deletion_supersedes_buffered_updates_while_other_owners_can_be_created(string $backend): void
    {
        $fixture = $this->stateFixture($backend, [OrderWithLinesState::class]);
        $fixture->store->save(new OrderWithLinesState('one', 'pending', [new OrderLineState('line', 'OLD', 1)]));
        $fixture->flush();
        $fixture->registry->clear();
        $fixture->store->observe(OrderWithLinesState::class, 'one');
        $fixture->store->save(new OrderWithLinesState('one', 'paid', [new OrderLineState('new', 'NEW', 3)]));
        $fixture->store->delete(OrderWithLinesState::class, 'one');
        $other = new OrderWithLinesState('two', 'pending', [new OrderLineState('line', 'OTHER', 2)]);
        $fixture->store->save($other);

        $fixture->flush();
        $fixture->registry->clear();
        static::assertNull($fixture->store->load(OrderWithLinesState::class, 'one'));
        static::assertEquals($other, $fixture->store->load(OrderWithLinesState::class, 'two'));
        static::assertSame(
            [],
            $fixture->collectionRows(
                $fixture->metadata->metadataFor(OrderWithLinesState::class)->collections['lines'],
                'one',
            ),
        );
    }
}
