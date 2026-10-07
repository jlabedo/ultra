<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;
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
        static::assertSame(['quantity' => 2], $database->rowByKey('composite_order_lines', $lineKey, ['quantity']));

        $loaded = $store->load(CompositeOrderState::class, new CompositeOrderId('tenant-1', 'order-42'));
        static::assertInstanceOf(CompositeOrderState::class, $loaded);
        static::assertCount(1, $loaded->lines);
        static::assertSame('blue', $loaded->lines[0]->lineId->variant);
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

        static::assertInstanceOf(OrderWithLinesState::class, $state);
        static::assertSame('pending', $state->status);
        static::assertContainsOnlyInstancesOf(OrderLineState::class, $state->lines);
        static::assertSame(
            ['line-1', 'line-2'],
            array_map(static fn(OrderLineState $line): string => $line->lineId, $state->lines),
        );
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

        static::assertSame(
            [
                'order_id' => 'order-123',
                'sku' => 'SKU-1',
                'quantity' => 3,
            ],
            $database->row(
                'order_lines',
                'line_id',
                'line-1',
                ['order_id', 'sku', 'quantity'],
                ['order_id' => 'order-123'],
            ),
        );
        static::assertFalse($database->row('order_lines', 'line_id', 'line-2'));
        static::assertSame(
            [
                'order_id' => 'order-123',
                'sku' => 'SKU-3',
                'quantity' => 1,
            ],
            $database->row(
                'order_lines',
                'line_id',
                'line-3',
                ['order_id', 'sku', 'quantity'],
                ['order_id' => 'order-123'],
            ),
        );
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
    public function test_collection_decisions_preserve_owner_membership(
        string $read,
        string $change,
        bool $save,
        bool $conflict,
    ): void {
        [$database, , $store, $registry] = $this->newStore([OrderWithLinesState::class]);
        $database->seedRow('orders', 'order_id', ['order_id' => 'order-123', 'status' => 'pending']);
        if ('delete' === $change) {
            $database->seedRow('order_lines', 'line_id', [
                'order_id' => 'order-123',
                'line_id' => 'old',
                'sku' => 'SKU',
                'quantity' => 1,
            ]);
        }
        $state = 'observe' === $read
            ? $store->observe(OrderWithLinesState::class, 'order-123')
            : $store->load(OrderWithLinesState::class, 'order-123');
        static::assertInstanceOf(OrderWithLinesState::class, $state);
        if ('expect' === $read) {
            $store->expect($state);
        }
        if ($save) {
            $store->save($state);
        }
        if ('insert' === $change) {
            $database->seedRow('order_lines', 'line_id', [
                'order_id' => 'order-123',
                'line_id' => 'late',
                'sku' => 'SKU',
                'quantity' => 1,
            ]);
        } else {
            $database->deleteRowByKey('order_lines', new \Ultra\MappedTable\MappedKey([
                'order_id' => 'order-123',
                'line_id' => 'old',
            ]));
        }
        if ($conflict) {
            $this->expectException(ConcurrencyException::class);
        }
        $this->flush($database, $registry);
        $registry->clear();
        static::assertSame([], $store->load(OrderWithLinesState::class, 'order-123')->lines);
    }

    public static function identifiers(): iterable
    {
        foreach (self::backends() as $name => [$backend]) {
            foreach (['string', 'integer', 'composite'] as $idType) {
                yield $name . ' ' . $idType => [$backend, $idType];
            }
        }
    }

    #[DataProvider('identifiers')]
    public function test_deletion_removes_only_the_owner_and_its_declared_collections(
        string $backend,
        string $idType,
    ): void {
        $fixture = $this->stateFixture($backend, [
            OrderWithLinesState::class,
            CompositeOrderState::class,
            CounterState::class,
        ]);
        [$state, $other, $id, $otherId] = match ($idType) {
            'string' => [
                new OrderWithLinesState('one', 'pending', [new OrderLineState('same', 'SKU', 1)]),
                new OrderWithLinesState('two', 'pending', [new OrderLineState('same', 'OTHER', 2)]),
                'one',
                'two',
            ],
            'integer' => [new CounterState(1, 3), new CounterState(2, 4), 1, 2],
            'composite' => [
                new CompositeOrderState(new CompositeOrderId('acme', 'one'), [new CompositeLineState(
                    new CompositeLineId('SKU', 'blue'),
                    1,
                )]),
                new CompositeOrderState(new CompositeOrderId('globex', 'one'), [new CompositeLineState(
                    new CompositeLineId('SKU', 'blue'),
                    2,
                )]),
                new CompositeOrderId('acme', 'one'),
                new CompositeOrderId('globex', 'one'),
            ],
        };
        $store = $fixture->store;
        $store->save($state);
        $store->save($other);
        $fixture->flush();
        $fixture->registry->clear();
        [$committed] = $fixture->newStore([$state::class]);

        $store->delete($state::class, $id);
        $store->delete($state::class, $id);
        static::assertNull($store->load($state::class, $id));
        static::assertNull($store->observe($state::class, $id));
        static::assertFalse($store->observeExists($state::class, $id));
        static::assertTrue($fixture->registry->hasPendingChanges());
        static::assertEquals($state, $committed->load($state::class, $id));

        $fixture->flush();
        $fixture->registry->clear();
        static::assertNull($store->load($state::class, $id));
        static::assertEquals($other, $store->load($state::class, $otherId));
        $normalizedId = is_object($id) ? ['tenantId' => $id->tenantId, 'orderNumber' => $id->orderNumber] : $id;
        foreach ($fixture->metadata->metadataFor($state::class)->collections as $collection) {
            static::assertSame([], $fixture->collectionRows($collection, $normalizedId));
        }
    }

    public static function deletionDecisions(): iterable
    {
        foreach (self::backends() as $name => [$backend]) {
            foreach ([
                'root value',
                'root value after buffered save',
                'root deletion',
                'collection addition',
                'empty collection addition',
                'collection removal',
                'missing row',
            ] as $change) {
                yield $name . ' ' . $change => [$backend, 'observe', $change, true];
            }
            yield $name . ' blind deletion imposes membership' => [$backend, null, 'blind additions', false];
            yield $name . ' presence only ignores values and membership' => [
                $backend,
                'observeExists',
                'root and collection change',
                false,
            ];
            yield $name . ' membership ignores item values' => [$backend, 'observe', 'item value change', false];
        }
    }

    #[DataProvider('deletionDecisions')]
    public function test_owner_deletion_protects_only_observed_dependencies_and_rolls_back_conflicts(
        string $backend,
        ?string $read,
        string $change,
        bool $conflict,
    ): void {
        $fixture = $this->stateFixture($backend, [OrderWithLinesState::class]);
        $initial = new OrderWithLinesState(
            'one',
            'pending',
            in_array($change, ['empty collection addition', 'blind additions'], true)
                ? []
                : [new OrderLineState('line', 'SKU', 1)],
        );
        if ('missing row' !== $change) {
            $fixture->store->save($initial);
            $fixture->flush();
            $fixture->registry->clear();
            if (null !== $read) {
                $fixture->store->$read(OrderWithLinesState::class, 'one');
            }
        }
        if ('root value after buffered save' === $change) {
            $fixture->store->save(new OrderWithLinesState('one', 'prepared', [new OrderLineState('new', 'NEW', 2)]));
        }
        $fixture->store->delete(OrderWithLinesState::class, 'one');
        [$competitor, $registry] = $fixture->newStore([OrderWithLinesState::class]);
        $competitor->observe(OrderWithLinesState::class, 'one');
        match ($change) {
            'root value', 'root value after buffered save' => $competitor->save(
                new OrderWithLinesState('one', 'paid', $initial->lines),
            ),
            'root deletion' => $competitor->delete(OrderWithLinesState::class, 'one'),
            'collection addition', 'empty collection addition' => $competitor->save(new OrderWithLinesState(
                'one',
                'pending',
                [...$initial->lines, new OrderLineState('late', 'LATE', 2)],
            )),
            'collection removal' => $competitor->save(new OrderWithLinesState('one', 'pending', [])),
            'blind additions', 'root and collection change' => $competitor->save(new OrderWithLinesState(
                'one',
                'changed',
                [new OrderLineState('late', 'LATE', 2)],
            )),
            'item value change' => $competitor->save(new OrderWithLinesState('one', 'pending', [new OrderLineState(
                'line',
                'CHANGED',
                2,
            )])),
            default => null,
        };
        $fixture->flush($registry);
        $registry->clear();
        $expected = $competitor->load(OrderWithLinesState::class, 'one');
        if ($conflict) {
            $this->expectException(ConcurrencyException::class);
        }
        try {
            $fixture->flush();
        } finally {
            $fixture->registry->clear();
            static::assertEquals(
                $conflict ? $expected : null,
                $fixture->store->load(OrderWithLinesState::class, 'one'),
            );
            if (!$conflict) {
                static::assertSame(
                    [],
                    $fixture->collectionRows(
                        $fixture->metadata->metadataFor(OrderWithLinesState::class)->collections['lines'],
                        'one',
                    ),
                );
            }
        }
    }
}
