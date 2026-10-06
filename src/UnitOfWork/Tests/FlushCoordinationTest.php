<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\UnitOfWork\CompositeUnitOfWork;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\Tests\Support\CallLog;
use Ultra\UnitOfWork\Tests\Support\RecordingTransactionManager;
use Ultra\UnitOfWork\Tests\Support\RecordingUnitOfWork;

#[CoversClass(CompositeUnitOfWork::class)]
#[CoversClass(TransactionalUnitOfWorkRunner::class)]
final class FlushCoordinationTest extends TestCase
{
    #[DataProvider('componentCollections')]
    public function test_components_keep_their_order_across_flush_and_clear(bool $generator): void
    {
        $log = new CallLog();
        $children = [new RecordingUnitOfWork($log, 'events'), new RecordingUnitOfWork($log, 'rows')];
        $components = $generator ? (static function () use ($children): \Generator {
            foreach ($children as $child) {
                yield 'same-key' => $child;
            }
        })() : ['events' => $children[0], 'rows' => $children[1]];
        $composite = new CompositeUnitOfWork($components);

        $composite->flush();
        $composite->clear();
        $composite->flush();

        self::assertSame([
            'events:flush', 'rows:flush',
            'events:clear', 'rows:clear',
            'events:flush', 'rows:flush',
        ], $log->entries);
    }

    /** @return iterable<string, array{bool}> */
    public static function componentCollections(): iterable
    {
        yield 'keyed array' => [false];
        yield 'generator with repeated keys' => [true];
    }

    /** @param list<bool> $pending */
    #[DataProvider('pendingComponents')]
    public function test_a_composite_is_pending_if_any_component_has_work(array $pending, bool $expected): void
    {
        $children = [];
        foreach ($pending as $value) {
            $child = new RecordingUnitOfWork(new CallLog());
            $child->pending = $value;
            $children[] = $child;
        }

        self::assertSame($expected, new CompositeUnitOfWork($children)->hasPendingChanges());
    }

    /** @return iterable<string, array{list<bool>, bool}> */
    public static function pendingComponents(): iterable
    {
        yield 'no components' => [[], false];
        yield 'all clean' => [[false, false], false];
        yield 'first dirty' => [[true, false], true];
        yield 'last dirty' => [[false, true], true];
    }

    /** @param list<string> $expected */
    #[DataProvider('workOutcomes')]
    public function test_work_finishes_before_transactional_flush_and_always_clears(string $outcome, array $expected): void
    {
        $log = new CallLog();
        $unitOfWork = new RecordingUnitOfWork($log);
        $failure = new \RuntimeException('failed '.$outcome);
        $unitOfWork->flushFailure = 'flush failure' === $outcome ? $failure : null;
        $runner = new TransactionalUnitOfWorkRunner($unitOfWork, new RecordingTransactionManager($log));
        $caught = null;
        $result = null;
        try {
            $result = $runner->run(function () use ($log, $unitOfWork, $outcome, $failure): string {
                $log->entries[] = 'work';
                $unitOfWork->pending = 'read only' !== $outcome;
                if ('work failure' === $outcome) {
                    throw $failure;
                }

                return 'result';
            });
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertSame(str_ends_with($outcome, 'failure') ? $failure : null, $caught);
        self::assertSame(null === $caught ? 'result' : null, $result);
        self::assertSame($expected, $log->entries);
        self::assertFalse($unitOfWork->hasPendingChanges());
        self::assertFalse($runner->hasActiveTransaction());
        self::assertFalse($runner->isRollbackOnly());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function workOutcomes(): iterable
    {
        yield 'successful write' => ['write', ['work', 'transaction:begin', 'unit_of_work:flush', 'transaction:commit', 'unit_of_work:clear']];
        yield 'read only' => ['read only', ['work', 'unit_of_work:clear']];
        yield 'work failure' => ['work failure', ['work', 'unit_of_work:clear']];
        yield 'flush failure' => ['flush failure', ['work', 'transaction:begin', 'unit_of_work:flush', 'transaction:rollback', 'unit_of_work:clear']];
    }
}
