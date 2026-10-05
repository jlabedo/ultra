<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryMappedRowSetAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\StateStore\Exception\PendingQueryChangesException;
use Ultra\StateStore\Exception\UnknownQueryGuardException;
use Ultra\StateStore\InMemoryGuardLockAdapter;
use Ultra\StateStore\GuardLockMode;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\StateTableRegistry;

/** @internal */
final class QueryReadOrderingTest extends TestCase
{
    public static function unknownGuards(): iterable
    {
        yield 'direct registry shared guard' => ['registryShared', 'unknown', []];
        yield 'direct registry exclusive guard' => ['registryExclusive', 'unknown', []];
        foreach (['lock', 'observe'] as $operation) {
            yield $operation.' with missing definition' => [$operation, 'company_usgae', []];
            yield $operation.' with empty definition' => [$operation, 'empty', ['empty' => []]];
            yield $operation.' with empty name' => [$operation, '', []];
        }
    }

    #[DataProvider('unknownGuards')]
    public function test_unknown_guards_are_rejected_without_reading_or_queuing_work(string $operation, string $name, array $definitions): void
    {
        $registry = new StateTableRegistry([], queryGuardDefinitions: $definitions);
        $guard = new QueryGuard($registry, $name);
        $called = false;
        try {
            if (str_starts_with($operation, 'registry')) {
                $registry->guard($name, 'acme', 'registryShared' === $operation ? GuardLockMode::SHARED : GuardLockMode::EXCLUSIVE);
            } elseif ('lock' === $operation) {
                $guard->lock('acme');
            } else {
                $guard->observe('acme', static function () use (&$called): int {
                    $called = true;

                    return 42;
                });
            }
            self::fail('A guard without watched-state metadata must not be usable.');
        } catch (UnknownQueryGuardException $exception) {
            self::assertSame($name, $exception->guardName);
            self::assertStringContainsString('registered for "'.$name.'"', $exception->getMessage());
            self::assertStringContainsString('#[DefineQueryGuard]', $exception->getMessage());
        }
        self::assertFalse($called);
        self::assertFalse($registry->hasPendingChanges());
    }

    public static function impactingMutations(): iterable
    {
        yield 'insert' => ['insert', 'acme'];
        yield 'update' => ['update', 'acme'];
        yield 'writer after an unrelated check-only row' => ['update_after_check', 'acme'];
        yield 'delete' => ['delete', 'acme'];
        yield 'move from queried scope' => ['move', 'acme'];
        yield 'move into queried scope' => ['move', 'globex'];
        yield 'scalar scope shares its string lock identity' => ['numeric', '123'];
    }

    #[DataProvider('impactingMutations')]
    public function test_affected_reads_are_rejected_before_executing_or_registering_the_query(string $mutation, string $scope): void
    {
        [, $table, , $registry, $guard] = $this->fixture();
        if ('update_after_check' === $mutation) {
            $table->withKey('a-check-only')->expectColumnValue('company_id', 'acme');
        }
        $row = $table->withKey('booking-1');
        if ('insert' === $mutation || 'numeric' === $mutation) {
            $row->insert()->setColumns(['company_id' => 'numeric' === $mutation ? 123 : 'acme', 'minutes' => 60]);
        } else {
            $row->expectColumnValue('company_id', 'acme');
            match ($mutation) {
                'update', 'update_after_check' => $row->setColumn('minutes', 120),
                'delete' => $row->delete(),
                'move' => $row->setColumn('company_id', 'globex'),
            };
        }
        $called = false;
        try {
            $guard->observe($scope, static function () use (&$called): int {
                $called = true;

                return 0;
            });
            self::fail('An affected query must not execute against committed rows alone.');
        } catch (PendingQueryChangesException $exception) {
            self::assertSame('company_usage', $exception->guardName);
            self::assertSame($scope, $exception->guardValue);
            self::assertSame('bookings', $exception->tableKey);
            self::assertSame('booking-1', $exception->key->value('id'));
            self::assertStringContainsString('before preparing affected writes', $exception->getMessage());
        }
        self::assertFalse($called);
        // Rejecting a read must add neither a shared guard nor a query expectation.
        $table->clear();
        self::assertFalse($registry->hasPendingChanges());
        self::assertSame(0, $guard->observe($scope, static fn (): int => 0));
    }

    public static function unrelatedChanges(): iterable
    {
        yield 'different scope' => ['scope'];
        yield 'move between other scopes' => ['move'];
        yield 'unwatched column' => ['column'];
        yield 'another guard on the same table' => ['guard'];
        yield 'different table' => ['table'];
        yield 'optimistic expectations alone' => ['expectation'];
        yield 'unchanged observed values' => ['unchanged'];
        yield 'manual guard alone' => ['lock'];
    }

    #[DataProvider('unrelatedChanges')]
    public function test_non_impacting_work_does_not_prevent_observation(string $change): void
    {
        [, $table, $otherTable, $registry, $guard] = $this->fixture();
        $row = $table->withKey('booking-1');
        match ($change) {
            'scope' => $row->insert()->setColumns(['company_id' => 'globex', 'minutes' => 60]),
            'move' => $row->expectColumnValue('company_id', 'globex')->setColumn('company_id', 'initech'),
            'column' => $row->setColumn('note', 'updated'),
            'guard' => $row->expectColumnValue('room_id', 'blue')->setColumn('status', 'cancelled'),
            'table' => $otherTable->withKey('note-1')->insert()->setColumn('text', 'hello'),
            'expectation' => $row->expectColumnValue('company_id', 'acme'),
            'unchanged' => $row->expectColumnValues(['company_id' => 'acme', 'minutes' => 60])->setColumn('minutes', 60),
            'lock' => $registry->lock('bookings', new \Ultra\MappedTable\MappedKey(['id' => 'booking-1'])),
        };

        self::assertSame(42, $guard->observe('acme', static fn (): int => 42));
    }

    public function test_unknown_previous_scope_is_rejected_without_executing_the_query(): void
    {
        [, $table, , , $guard] = $this->fixture();
        $table->withKey('booking-1')->setColumn('minutes', 60);
        $called = false;
        try {
            $guard->observe('acme', static function () use (&$called): int {
                $called = true;

                return 0;
            });
            self::fail('An unknown old scope cannot be assumed unrelated.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('requires observing column "company_id"', $exception->getMessage());
        }
        self::assertFalse($called);
    }

    public function test_flush_replays_an_earlier_observation_even_with_subsequent_affected_writes(): void
    {
        [$database, $table, , $registry, $guard] = $this->fixture();
        $phases = [];
        $guard->observe('acme', static function () use ($database, &$phases): bool {
            $phases[] = $database->isTransactionActive();

            return false !== $database->row('bookings', 'id', 'booking-1');
        });
        $table->withKey('booking-1')->insert()->setColumns(['company_id' => 'acme', 'room_id' => 'blue', 'minutes' => 60]);
        $database->beginTransaction();
        try {
            $registry->flush();
            $database->commit();
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
        }
        self::assertSame([false, true], $phases);
        self::assertSame(60, $database->row('bookings', 'id', 'booking-1')['minutes']);
        $registry->clear();
        self::assertSame(true, $guard->observe('acme', static fn (): bool => true));
    }

    /** @return array{InMemoryRowDatabase, MappedRowSet, MappedRowSet, StateTableRegistry, QueryGuard} */
    private function fixture(): array
    {
        $database = new InMemoryRowDatabase();
        $table = new MappedRowSet(new InMemoryMappedRowSetAdapter($database, 'bookings'));
        $otherTable = new MappedRowSet(new InMemoryMappedRowSetAdapter($database, 'notes'));
        $registry = new StateTableRegistry(['bookings' => $table, 'notes' => $otherTable], queryGuardDefinitions: [
            'company_usage' => [['tableKey' => 'bookings', 'keyBy' => 'company_id', 'columns' => ['charged', 'minutes']]],
            'room_bookings' => [['tableKey' => 'bookings', 'keyBy' => 'room_id', 'columns' => ['status']]],
        ], guardLockAdapter: new InMemoryGuardLockAdapter($database));

        return [$database, $table, $otherTable, $registry, new QueryGuard($registry, 'company_usage')];
    }
}
