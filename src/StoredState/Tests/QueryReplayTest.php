<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Ultra\StateStore\Tests\Support\StateStoreTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final class QueryReplayTest extends StateStoreTestCase
{
    public function test_observed_query_is_replayed_in_the_flush_transaction_before_writes(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class]);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-1', 'status' => 'pending', 'note' => null]);
        $transactions = [];
        $query = static function () use ($database, &$transactions): string {
            $transactions[] = $database->isTransactionActive();

            return $database->row('orders', 'order_id', 'order-1')['status'];
        };

        static::assertSame('pending', $store->observeQuery($query, 'order status'));
        $store->save(new OrderState('order-1', 'confirmed', null));
        $this->flush($database, $registry);

        static::assertSame([false, true], $transactions);
        static::assertSame('confirmed', $store->load(OrderState::class, 'order-1')->orderStatus);
        $registry->clear();
        static::assertFalse($registry->hasPendingChanges());
    }

    public function test_a_changed_manual_query_rejects_the_entire_flush_and_clear_drops_it(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class]);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-1', 'status' => 'pending', 'note' => null]);
        $query = static fn(): array => $database->row('orders', 'order_id', 'order-1', ['status']);
        $store->expectQuery($query, ['status' => 'pending'], 'manual order check');
        $store->save(new OrderState('order-2', 'confirmed', null));
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-1', 'status' => 'cancelled', 'note' => null]);

        try {
            $this->flush($database, $registry);
            static::fail('A changed query must reject the flush.');
        } catch (ConcurrencyException $exception) {
            static::assertStringContainsString('manual order check', $exception->getMessage());
        }
        $registry->clear();
        static::assertNull($store->load(OrderState::class, 'order-2'));
        static::assertFalse($registry->hasPendingChanges());
        $this->flush($database, $registry);
    }

    public function test_a_query_only_unit_of_work_is_flushed_and_compares_types_strictly(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class]);
        $store->expectQuery(static fn(): string => '1', 1);
        static::assertTrue($registry->hasPendingChanges());
        $this->expectException(ConcurrencyException::class);
        $this->flush($database, $registry);
    }

    public function test_query_expectations_reject_mutable_values(): void
    {
        [, , $store] = $this->newStore([OrderState::class]);
        $this->expectException(\InvalidArgumentException::class);
        $store->expectQuery(static fn(): array => [], ['nested' => new \stdClass()]);
    }
}
