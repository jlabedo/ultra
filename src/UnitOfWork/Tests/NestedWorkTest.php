<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\Tests\Support\CallLog;
use Ultra\UnitOfWork\Tests\Support\RecordingTransactionManager;
use Ultra\UnitOfWork\Tests\Support\RecordingUnitOfWork;

#[CoversClass(TransactionalUnitOfWorkRunner::class)]
final class NestedWorkTest extends TestCase
{
    #[DataProvider('nestedOutcomes')]
    public function test_nested_work_joins_the_outer_run_and_failures_prevent_its_flush(bool $fails, bool $swallow): void
    {
        $log = new CallLog();
        $unitOfWork = new RecordingUnitOfWork($log);
        $runner = new TransactionalUnitOfWorkRunner($unitOfWork, new RecordingTransactionManager($log));
        $failure = new \RuntimeException('inner failed');
        $caught = null;
        try {
            $runner->run(function () use ($runner, $unitOfWork, $log, $failure, $fails, $swallow): void {
                $unitOfWork->pending = true;
                $log->entries[] = 'outer';
                try {
                    $result = $runner->run(function () use ($runner, $log, $failure, $fails): string {
                        self::assertTrue($runner->hasActiveTransaction());
                        $log->entries[] = 'inner';
                        if ($fails) {
                            throw $failure;
                        }

                        return 'inner result';
                    });
                    self::assertSame('inner result', $result);
                } catch (\RuntimeException $exception) {
                    self::assertSame($failure, $exception);
                    self::assertTrue($runner->isRollbackOnly());
                    if (!$swallow) {
                        throw $exception;
                    }
                    $log->entries[] = 'caught';
                }
                self::assertTrue($runner->hasActiveTransaction());
            });
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        $expected = ['outer', 'inner'];
        if ($swallow) {
            $expected[] = 'caught';
        }
        if (!$fails) {
            $expected = [...$expected, 'transaction:begin', 'unit_of_work:flush', 'transaction:commit'];
        }
        self::assertSame([...$expected, 'unit_of_work:clear'], $log->entries);
        self::assertSame($fails && !$swallow ? $failure : null, $caught);
        self::assertFalse($runner->hasActiveTransaction());
        self::assertFalse($runner->isRollbackOnly());

        $log->entries = [];
        $runner->run(static function () use ($unitOfWork): void {
            $unitOfWork->pending = true;
        });
        self::assertSame(['transaction:begin', 'unit_of_work:flush', 'transaction:commit', 'unit_of_work:clear'], $log->entries);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function nestedOutcomes(): iterable
    {
        yield 'success' => [false, false];
        yield 'propagated failure' => [true, false];
        yield 'caught failure' => [true, true];
    }

    public function test_explicit_rollback_only_applies_to_an_active_run(): void
    {
        $log = new CallLog();
        $unitOfWork = new RecordingUnitOfWork($log);
        $runner = new TransactionalUnitOfWorkRunner($unitOfWork, new RecordingTransactionManager($log));

        $runner->markRollbackOnly();
        self::assertFalse($runner->isRollbackOnly());
        $runner->run(function () use ($runner, $unitOfWork): void {
            $unitOfWork->pending = true;
            $runner->markRollbackOnly();
            self::assertTrue($runner->isRollbackOnly());
        });

        self::assertSame(['unit_of_work:clear'], $log->entries);
        self::assertFalse($runner->isRollbackOnly());
    }
}
