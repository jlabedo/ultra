<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\StateStore\Tests\Support\OrdersDefinition;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;

/** @internal */
final class FlushCoordinationTest extends StateStoreTestCase
{
    public function test_state_locks_use_the_same_mapped_row_for_different_state_views(): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderState::class, OrderStatusView::class]);
        $store->lock(new OrderState('order-1', 'pending', null));
        $store->lock(new OrderStatusView('order-1', 'pending'));
        static::assertTrue($registry->hasPendingChanges());
        $this->flush($database, $registry);
        static::assertNull($store->load(OrderState::class, 'order-1'));
        $registry->clear();
        static::assertFalse($registry->hasPendingChanges());
    }

    #[DataProvider('backends')]
    public function test_deletion_with_an_unknown_query_scope_fails_before_removing_collections(string $backend): void
    {
        $fixture = $this->stateFixture($backend, [OrderWithLinesState::class]);
        $initial = new OrderWithLinesState('one', 'pending', [new OrderLineState('line', 'SKU', 1)]);
        $fixture->store->save($initial);
        $fixture->flush();
        $fixture->unitOfWork->clear();
        [$store, $registry] = $fixture->newStore([OrderWithLinesState::class], guards: [
            new QueryGuardDefinition('usage', [
                new QueryGuardDependency(OrdersDefinition::class, 'status', ['status']),
            ]),
        ]);
        $store->delete(OrderWithLinesState::class, 'one');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires an expectation on "status"');
        try {
            $fixture->flush($registry);
        } finally {
            static::assertEquals($initial, $fixture->store->load(OrderWithLinesState::class, 'one'));
        }
    }
}
