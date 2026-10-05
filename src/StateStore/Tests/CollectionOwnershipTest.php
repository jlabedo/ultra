<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;
use Ultra\StateStore\StateStore;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final class CollectionOwnershipTest extends StateStoreTestCase
{
    public function test_collection_combines_composite_owner_and_item_keys(): void
    {
        [$database, , $store, $registry] = $this->newStore([CompositeOrderState::class]);

        $id = new CompositeOrderId('tenant-1', 'order-42');
        $store->save(new CompositeOrderState($id, [
            new CompositeLineState(new CompositeLineId('sku-1', 'blue'), 2),
        ]));
        $this->flush($database, $registry);

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

        $this->flush($database, $registry);

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




    public static function membershipDecisions(): iterable
    {
        yield 'blind save imposes membership' => ['load', 'insert', true, false];
        yield 'observe protects an empty collection' => ['observe', 'insert', true, true];
        yield 'explicit expectation protects additions' => ['expect', 'insert', true, true];
        yield 'explicit expectation protects deletions' => ['expect', 'delete', true, true];
        yield 'read-only observation protects membership' => ['observe', 'insert', false, true];
    }

    #[DataProvider('membershipDecisions')]
    public function test_collection_decisions_preserve_owner_membership(string $read, string $change, bool $save, bool $conflict): void
    {
        [$database, , $store, $registry] = $this->newStore([OrderWithLinesState::class]);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'pending']);
        if ('delete' === $change) {
            $database->seedRow('order_lines', 'line_id', ['order_id' => 'order-123', 'line_id' => 'old', 'sku' => 'SKU', 'quantity' => 1]);
        }
        $state = 'observe' === $read ? $store->observe(OrderWithLinesState::class, 'order-123') : $store->load(OrderWithLinesState::class, 'order-123');
        self::assertInstanceOf(OrderWithLinesState::class, $state);
        if ('expect' === $read) {
            $store->expect($state);
        }
        if ($save) {
            $store->save($state);
        }
        if ('insert' === $change) {
            $database->seedRow('order_lines', 'line_id', ['order_id' => 'order-123', 'line_id' => 'late', 'sku' => 'SKU', 'quantity' => 1]);
        } else {
            $database->deleteRowByKey('order_lines', new \Ultra\MappedTable\MappedKey(['order_id' => 'order-123', 'line_id' => 'old']));
        }
        if ($conflict) {
            $this->expectException(ConcurrencyException::class);
        }
        $this->flush($database, $registry);
        $registry->clear();
        self::assertSame([], $store->load(OrderWithLinesState::class, 'order-123')->lines);
    }
}
