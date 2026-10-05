<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Ultra\StateStore\Tests\Support\StateStoreTestCase;
use Ultra\StateStore\Tests\OrderState;
use Ultra\StateStore\Tests\OrderStatusView;
use Ultra\StateStore\GuardLockMode;
use Ultra\StateStore\GuardLockAdapter;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\RowSetUpdatePlan;
use Ultra\StateStore\StateTableRegistry;

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
        $registry = new StateTableRegistry(['first' => $first, 'last' => $last, 'same' => $sameTable], queryGuardDefinitions: [
            'usage' => [['tableKey' => 'last', 'keyBy' => 'id', 'columns' => null]],
            'availability' => [['tableKey' => 'last', 'keyBy' => 'id', 'columns' => null]],
        ], guardLockAdapter: new RegistryRecordingGuardAdapter($trace));
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

        self::assertSame([
            'guard:availability:room:SHARED', 'guard:usage:1:SHARED', 'guard:usage:2:EXCLUSIVE',
            'lock:a:1', 'lock:a:2', 'lock:z:2', 'validate', 'apply:a', 'apply:z', 'apply:a',
        ], $trace->getArrayCopy());
    }

    public function test_manual_guards_belong_to_the_registry_without_creating_a_row_set(): void
    {
        $trace = new \ArrayObject();
        $registry = new StateTableRegistry([], guardLockAdapter: new RegistryRecordingGuardAdapter($trace));
        $registry->lock('companies', new MappedKey(['id' => 'company']));
        self::assertTrue($registry->hasPendingChanges());

        $registry->flush();

        self::assertSame(['lock:companies:company'], $trace->getArrayCopy());
        $registry->clear();
        self::assertFalse($registry->hasPendingChanges());
        $trace->exchangeArray([]);
        $registry->flush();
        self::assertSame([], $trace->getArrayCopy());
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
        self::assertTrue($registry->hasPendingChanges());
        $this->flush($database, $registry);
        self::assertNull($store->load(OrderState::class, 'order-1'));
        $registry->clear();
        self::assertFalse($registry->hasPendingChanges());
    }
}

/** Records the coordination protocol; Postgres tests cover transaction-level lock behavior. */
final readonly class RegistryRecordingAdapter implements MappedRowSetAdapter
{
    public function __construct(private string $name, private \ArrayObject $trace) {}

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
        $this->trace[] = 'apply:'.$this->name;
    }
}

final readonly class RegistryRecordingGuardAdapter implements GuardLockAdapter
{
    public function __construct(private \ArrayObject $trace) {}

    public function acquire(string $namespace, string $key, GuardLockMode $mode): void
    {
        $this->trace[] = str_starts_with($namespace, 'ultra:query_guard:')
            ? 'guard:'.substr($namespace, strlen('ultra:query_guard:')).':'.$key.':'.$mode->name
            : 'lock:'.substr($namespace, 0, -strlen(':manual')).':'.json_decode($key, true, flags: JSON_THROW_ON_ERROR)['id'];
    }
}
