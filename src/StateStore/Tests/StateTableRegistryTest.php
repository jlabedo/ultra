<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\StateStore\GuardLockMode;
use Ultra\StateStore\GuardLockAdapter;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\RowSetUpdatePlan;
use Ultra\StateStore\StateTableRegistry;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
final class StateTableRegistryTest extends TestCase
{
    public function test_flush_consolidates_and_orders_all_guards_before_queries_and_writes(): void
    {
        $trace = new \ArrayObject();
        $first = new MappedRowSet(new RegistryRecordingAdapter('a', $trace));
        $sameTable = new MappedRowSet(new RegistryRecordingAdapter('a', $trace));
        $last = new MappedRowSet(new RegistryRecordingAdapter('z', $trace));
        $first->withKey('new')->insert()->setColumn('status', 'pending');
        $registry = new StateTableRegistry(['first' => $first, 'last' => $last, 'same' => $sameTable], guardLockAdapter: new RegistryRecordingGuardAdapter($trace));
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

    public function test_a_failed_query_prevents_every_table_plan_from_being_applied(): void
    {
        $trace = new \ArrayObject();
        $first = new MappedRowSet(new RegistryRecordingAdapter('a', $trace));
        $second = new MappedRowSet(new RegistryRecordingAdapter('b', $trace));
        $first->withKey('one')->insert()->setColumn('status', 'pending');
        $second->withKey('two')->insert()->setColumn('status', 'pending');
        $registry = new StateTableRegistry(['a' => $first, 'b' => $second], guardLockAdapter: new RegistryRecordingGuardAdapter($trace));
        $registry->lock('a', new MappedKey(['id' => 'anchor']));
        $registry->expectQuery(static function () use ($trace): bool {
            $trace[] = 'validate';

            return false;
        }, true, 'changed query');

        try {
            $registry->flush();
            self::fail('The query expectation should fail before applying any plan.');
        } catch (ConcurrencyException $exception) {
            self::assertStringContainsString('changed query', $exception->getMessage());
            self::assertSame(['lock:a:anchor', 'validate'], $trace->getArrayCopy());
        }
        $registry->clear();
        self::assertFalse($registry->hasPendingChanges());
    }
    public function test_guards_belong_to_the_registry_without_creating_a_row_set(): void
    {
        $trace = new \ArrayObject();
        $registry = new StateTableRegistry([], guardLockAdapter: new RegistryRecordingGuardAdapter($trace));
        $registry->guard('usage', 'company', GuardLockMode::SHARED);
        $registry->lock('companies', new MappedKey(['id' => 'company']));
        self::assertTrue($registry->hasPendingChanges());

        $registry->flush();

        self::assertSame(['guard:usage:company:SHARED', 'lock:companies:company'], $trace->getArrayCopy());
        $registry->clear();
        self::assertFalse($registry->hasPendingChanges());
        $trace->exchangeArray([]);
        $registry->flush();
        self::assertSame([], $trace->getArrayCopy());
    }

    public function test_guarded_flush_requires_a_dedicated_adapter(): void
    {
        $registry = new StateTableRegistry([]);
        $registry->guard('usage', 'company', GuardLockMode::SHARED);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('GuardLockAdapter');
        $registry->flush();
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
