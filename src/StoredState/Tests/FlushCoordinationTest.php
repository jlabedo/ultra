<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\RowSetUpdatePlan;
use Ultra\StateStore\GuardLockAdapter;
use Ultra\StateStore\GuardLockMode;
use Ultra\StateStore\QueryGuardDefinition;
use Ultra\StateStore\StateTableRegistry;
use Ultra\StateStore\Tests\Support\StateStoreTestCase;

/** @internal */
final class FlushCoordinationTest extends StateStoreTestCase
{
    public function test_flush_consolidates_and_orders_all_guards_before_queries_and_writes(): void
    {
        $trace = new \ArrayObject();
        $first = new MappedRowSet(new RegistryRecordingAdapter('a', $trace));
        $sameTable = new MappedRowSet(new RegistryRecordingAdapter('a', $trace));
        $last = new MappedRowSet(new RegistryRecordingAdapter('z', $trace));
        $first->withKey('new')->insert()->setColumn('status', 'pending');
        $registry = new StateTableRegistry(
            ['first' => $first, 'last' => $last, 'same' => $sameTable],
            queryGuardDefinitions: [
                'usage' => [['tableKey' => 'last', 'keyBy' => 'id', 'columns' => null]],
                'availability' => [['tableKey' => 'last', 'keyBy' => 'id', 'columns' => null]],
            ],
            guardLockAdapter: new RegistryRecordingGuardAdapter($trace),
        );
        $registry->lock('z', new MappedKey(['id' => '2']));
        $registry->lock('a', new MappedKey(['id' => '2']));
        $registry->lock('a', new MappedKey(['id' => '1']));
        $registry->lock('a', new MappedKey(['id' => '2']));
        $registry->guard('usage', '2', GuardLockMode::SHARED);
        $registry->guard('usage', '1', GuardLockMode::SHARED);
        $registry->guard('usage', '2', GuardLockMode::EXCLUSIVE);
        $registry->guard('usage', '2', GuardLockMode::SHARED);
        $registry->guard('availability', 'room', GuardLockMode::SHARED);
        $registry->expectQuery(static function () use ($trace): bool {
            $trace[] = 'validate';

            return true;
        }, true);

        $registry->flush();

        static::assertSame(
            [
                'guard:availability:room:SHARED',
                'guard:usage:1:SHARED',
                'guard:usage:2:EXCLUSIVE',
                'lock:a:1',
                'lock:a:2',
                'lock:z:2',
                'validate',
                'apply:a',
                'apply:z',
                'apply:a',
            ],
            $trace->getArrayCopy(),
        );
    }

    public function test_manual_guards_belong_to_the_registry_without_creating_a_row_set(): void
    {
        $trace = new \ArrayObject();
        $registry = new StateTableRegistry([], guardLockAdapter: new RegistryRecordingGuardAdapter($trace));
        $registry->lock('companies', new MappedKey(['id' => 'company']));
        static::assertTrue($registry->hasPendingChanges());

        $registry->flush();

        static::assertSame(['lock:companies:company'], $trace->getArrayCopy());
        $registry->clear();
        static::assertFalse($registry->hasPendingChanges());
        $trace->exchangeArray([]);
        $registry->flush();
        static::assertSame([], $trace->getArrayCopy());
    }

    public function test_guarded_flush_requires_a_dedicated_adapter(): void
    {
        $registry = new StateTableRegistry([]);
        $registry->lock('companies', new MappedKey(['id' => 'company']));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('GuardLockAdapter');
        $registry->flush();
    }

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
        $fixture->registry->clear();
        $definition = new QueryGuardDefinition();
        $definition->watch(OrderWithLinesState::class, scopeBy: 'status')->whenPropertiesChange('status');
        [$store, $registry] = $fixture->newStore([OrderWithLinesState::class], [
            'usage' => $definition->compile($fixture->metadata),
        ]);
        $store->delete(OrderWithLinesState::class, 'one');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires observing column "status"');
        try {
            $fixture->flush($registry);
        } finally {
            static::assertEquals($initial, $fixture->store->load(OrderWithLinesState::class, 'one'));
        }
    }
}

/** Records the coordination protocol; Postgres tests cover transaction-level lock behavior. */
final readonly class RegistryRecordingAdapter implements MappedRowSetAdapter
{
    public function __construct(
        private string $name,
        private \ArrayObject $trace,
    ) {}

    public function keyColumns(): array
    {
        return ['id'];
    }

    public function scope(): ?MappedKey
    {
        return null;
    }

    public function row(MappedKey $key, array $columns = []): array|false
    {
        return false;
    }

    public function rows(array $columns = []): array
    {
        return [];
    }

    public function apply(RowSetUpdatePlan $plan): void
    {
        $this->trace[] = 'apply:' . $this->name;
    }
}

final readonly class RegistryRecordingGuardAdapter implements GuardLockAdapter
{
    public function __construct(
        private \ArrayObject $trace,
    ) {}

    public function acquire(string $namespace, string $key, GuardLockMode $mode): void
    {
        $this->trace[] = str_starts_with($namespace, 'ultra:query_guard:')
            ? 'guard:' . substr($namespace, strlen('ultra:query_guard:')) . ':' . $key . ':' . $mode->name
            : 'lock:'
            . substr($namespace, 0, -strlen(':manual'))
            . ':'
            . json_decode($key, true, flags: JSON_THROW_ON_ERROR)['id'];
    }
}
