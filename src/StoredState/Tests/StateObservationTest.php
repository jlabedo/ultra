<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final class StateObservationTest extends StateStoreTestCase
{
    public function test_expectation_from_one_state_class_can_guard_save_of_another_on_same_row(): void
    {
        [$database, $table, $store] = $this->newStore([OrderState::class, OrderStatusView::class]);
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            'status' => 'pending',
            'note' => 'draft',
        ]);

        $view = $store->load(OrderStatusView::class, 'order-123');
        static::assertInstanceOf(OrderStatusView::class, $view);

        $store->expect($view);
        $store->save(new OrderState('order-123', 'shipped', 'confirmed'));
        $this->flush($database, $table);

        static::assertSame(
            [
                'status' => 'shipped',
                'note' => 'confirmed',
            ],
            $database->row('orders', 'order_id', 'order-123', ['status', 'note']),
        );
    }

    public static function valueObservations(): iterable
    {
        yield 'read-only explicit expectation' => ['expect', false];
        yield 'explicit expectation followed by save' => ['expect', true];
        yield 'read-only observation' => ['observe', false];
    }

    #[DataProvider('valueObservations')]
    public function test_value_observations_guard_storage_even_without_a_save(string $read, bool $save): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class]);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'pending', 'note' => null]);
        $state = 'observe' === $read
            ? $store->observe(OrderState::class, 'order-123')
            : $store->load(OrderState::class, 'order-123');
        static::assertEquals(new OrderState('order-123', 'pending', null), $state);
        if ('expect' === $read) {
            $store->expect($state);
        }
        static::assertFalse($database->isTransactionActive());
        static::assertTrue($registry->hasPendingChanges());
        $this->flush($database, $registry);
        static::assertSame('pending', $database->row('orders', 'order_id', 'order-123')['status']);
        if ($save) {
            $store->save($state->withOrderStatus('shipped'));
        }
        $database->writeRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'paid', 'note' => null]);
        $this->expectException(ConcurrencyException::class);
        $this->flush($database, $registry);
    }

    public static function missingObservations(): iterable
    {
        foreach (['expectMissing', 'observe', 'observeExists'] as $read) {
            yield $read . ' without save' => [$read, false];
            yield $read . ' followed by own creation' => [$read, true];
        }
    }

    #[DataProvider('missingObservations')]
    public function test_missing_observations_neither_write_nor_lose_the_absence_expectation(
        string $read,
        bool $save,
    ): void {
        [$database, , $store, $registry] = $this->newStore([OrderState::class]);
        $result = $store->$read(OrderState::class, 'order-123');
        static::assertSame('observeExists' === $read ? false : null, $result);
        static::assertTrue($registry->hasPendingChanges());
        $this->flush($database, $registry);
        static::assertNull($store->load(OrderState::class, 'order-123'));
        if ($save) {
            $store->save(new OrderState('order-123', 'pending', null));
            static::assertTrue($store->observeExists(OrderState::class, 'order-123'));
        }
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'competing', 'note' => null]);
        $this->expectException(ConcurrencyException::class);
        $this->flush($database, $registry);
    }

    public static function presenceObservations(): iterable
    {
        yield 'existence selects only identity without hydrating collections' => [
            'observeExists',
            OrderWithLinesState::class,
        ];
        yield 'identity-only observation' => ['observe', OrderIdentityState::class];
        yield 'non-optimistic columns' => ['observe', OrderUnwatchedState::class];
    }

    #[DataProvider('presenceObservations')]
    public function test_presence_observations_ignore_values_and_collections_but_reject_deletion(
        string $read,
        string $class,
    ): void {
        [$database, , $store, $registry] = $this->newStore([$class]);
        // A presence-only read cannot hydrate OrderWithLinesState: its status is absent.
        $database->seedRow('orders', 'order_id', [
            'order_id' => 'order-123',
            ...('observeExists' === $read ? [] : ['status' => 'pending']),
        ]);
        $result = $store->$read($class, 'order-123');
        static::assertSame(
            'observeExists' === $read ? true : 'order-123',
            is_object($result) ? $result->orderId : $result,
        );
        static::assertFalse($database->isTransactionActive());
        static::assertTrue($registry->hasPendingChanges());
        $database->writeRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'paid']);
        $database->seedRow('order_lines', 'line_id', [
            'order_id' => 'order-123',
            'line_id' => 'new',
            'sku' => 'SKU',
            'quantity' => 1,
        ]);
        $this->flush($database, $registry);
        static::assertSame('paid', $database->row('orders', 'order_id', 'order-123')['status']);
        $database->deleteRowByKey('orders', new \Ultra\MappedTable\MappedKey(['order_id' => 'order-123']));
        $this->expectException(ConcurrencyException::class);
        $this->flush($database, $registry);
    }

    public static function clearableObservations(): iterable
    {
        yield 'presence' => ['observeExists'];
        yield 'identity-only state' => ['observe'];
    }

    #[DataProvider('clearableObservations')]
    public function test_clear_discards_observations_after_own_creation(string $read): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderIdentityState::class]);
        $store->$read(OrderIdentityState::class, 'order-123');
        $store->save(new OrderIdentityState('order-123'));
        static::assertTrue($store->observeExists(OrderIdentityState::class, 'order-123'));
        $this->flush($database, $registry);
        $registry->clear();
        $store->$read(OrderIdentityState::class, 'order-123');
        $registry->clear();
        $database->deleteRowByKey('orders', new \Ultra\MappedTable\MappedKey(['order_id' => 'order-123']));
        static::assertFalse($registry->hasPendingChanges());
        $this->flush($database, $registry);
        static::assertNull($store->load(OrderIdentityState::class, 'order-123'));
    }

    public static function absenceDecisions(): iterable
    {
        foreach (self::backends() as $name => [$backend]) {
            foreach ([false, true] as $insert) {
                yield $name . ($insert ? ' cancelled insertion' : ' observed absence') => [$backend, $insert];
            }
        }
    }

    #[DataProvider('absenceDecisions')]
    public function test_deletion_retains_absence_expectations_and_cancels_own_insertions(
        string $backend,
        bool $insert,
    ): void {
        $fixture = $this->stateFixture($backend, [OrderWithLinesState::class]);
        static::assertNull($fixture->store->observe(OrderWithLinesState::class, 'one'));
        if ($insert) {
            $fixture->store->save(new OrderWithLinesState('one', 'pending', [new OrderLineState('line', 'SKU', 1)]));
        }
        $fixture->store->delete(OrderWithLinesState::class, 'one');
        static::assertNull($fixture->store->load(OrderWithLinesState::class, 'one'));
        $fixture->flush();
        static::assertSame(
            [],
            $fixture->collectionRows(
                $fixture->metadata->metadataFor(OrderWithLinesState::class)->collections['lines'],
                'one',
            ),
        );

        [$competitor, $registry] = $fixture->newStore([OrderWithLinesState::class]);
        $competitor->save(new OrderWithLinesState('one', 'competing', []));
        $fixture->flush($registry);
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            static::assertSame('competing', $competitor->load(OrderWithLinesState::class, 'one')->status);
        }
    }

    #[DataProvider('backends')]
    public function test_deletion_preserves_expectations_registered_through_another_state_view(string $backend): void
    {
        $fixture = $this->stateFixture($backend, [OrderState::class, OrderStatusView::class]);
        $fixture->store->save(new OrderState('one', 'pending', 'draft'));
        $fixture->flush();
        $fixture->registry->clear();
        $fixture->store->observe(OrderState::class, 'one');
        $fixture->store->delete(OrderStatusView::class, 'one');
        [$competitor, $registry] = $fixture->newStore([OrderState::class]);
        $competitor->save(new OrderState('one', 'pending', 'changed'));
        $fixture->flush($registry);
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }
}
