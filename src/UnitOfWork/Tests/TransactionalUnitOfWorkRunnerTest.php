<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkRunner;

#[CoversClass(TransactionalUnitOfWorkRunner::class)]
final class TransactionalUnitOfWorkRunnerTest extends TestCase
{
    public function test_it_runs_work_then_flushes_inside_a_transaction_and_clears(): void
    {
        $log = new TransactionalRunnerLog();
        $runner = new TransactionalUnitOfWorkRunner(
            new TransactionalRunnerUnitOfWork($log),
            new TransactionalRunnerTransactionManager($log),
        );

        $result = $runner->run(function () use ($log): string {
            $log->entries[] = 'work';

            return 'result';
        });

        self::assertSame('result', $result);
        self::assertSame([
            'work',
            'transaction:begin',
            'unit_of_work:flush',
            'transaction:commit',
            'unit_of_work:clear',
        ], $log->entries);
    }

    public function test_it_clears_and_rethrows_when_work_fails(): void
    {
        $log = new TransactionalRunnerLog();
        $runner = new TransactionalUnitOfWorkRunner(
            new TransactionalRunnerUnitOfWork($log),
            new TransactionalRunnerTransactionManager($log),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $runner->run(function () use ($log): never {
                $log->entries[] = 'work';

                throw new \RuntimeException('boom');
            });
        } finally {
            self::assertSame([
                'work',
                'unit_of_work:clear',
            ], $log->entries);
        }
    }

    public function test_it_clears_when_transactional_flush_fails(): void
    {
        $log = new TransactionalRunnerLog();
        $runner = new TransactionalUnitOfWorkRunner(
            new TransactionalRunnerFailingUnitOfWork($log),
            new TransactionalRunnerTransactionManager($log),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('flush failed');

        try {
            $runner->run(function () use ($log): void {
                $log->entries[] = 'work';
            });
        } finally {
            self::assertSame([
                'work',
                'transaction:begin',
                'unit_of_work:flush',
                'transaction:rollback',
                'unit_of_work:clear',
            ], $log->entries);
        }
    }

    public function test_it_skips_transaction_when_unit_of_work_has_no_pending_changes(): void
    {
        $log = new TransactionalRunnerLog();
        $runner = new TransactionalUnitOfWorkRunner(
            new TransactionalRunnerUnitOfWork($log, hasPendingChanges: false),
            new TransactionalRunnerTransactionManager($log),
        );

        $runner->run(function () use ($log): void {
            $log->entries[] = 'work';
        });

        self::assertSame([
            'work',
            'unit_of_work:clear',
        ], $log->entries);
    }

    public function test_nested_failure_marks_outer_run_rollback_only_even_when_swallowed(): void
    {
        $log = new TransactionalRunnerLog();
        $runner = new TransactionalUnitOfWorkRunner(
            new TransactionalRunnerUnitOfWork($log),
            new TransactionalRunnerTransactionManager($log),
        );

        $runner->run(function () use ($runner, $log): void {
            $log->entries[] = 'outer';

            try {
                $runner->run(function () use ($log): never {
                    $log->entries[] = 'inner';

                    throw new \RuntimeException('swallowed');
                });
            } catch (\RuntimeException) {
                $log->entries[] = 'caught';
            }
        });

        self::assertSame([
            'outer',
            'inner',
            'caught',
            'unit_of_work:clear',
        ], $log->entries);
    }

    public function test_it_implements_unit_of_work_runner(): void
    {
        $runner = new TransactionalUnitOfWorkRunner(
            new TransactionalRunnerUnitOfWork(new TransactionalRunnerLog()),
            new TransactionalRunnerTransactionManager(new TransactionalRunnerLog()),
        );

        self::assertInstanceOf(UnitOfWorkRunner::class, $runner);
    }
}

final class TransactionalRunnerLog
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class TransactionalRunnerUnitOfWork implements UnitOfWork
{
    public function __construct(
        private TransactionalRunnerLog $log,
        private bool $hasPendingChanges = true,
    ) {}

    public function hasPendingChanges(): bool
    {
        return $this->hasPendingChanges;
    }

    public function flush(): void
    {
        $this->log->entries[] = 'unit_of_work:flush';
    }

    public function clear(): void
    {
        $this->log->entries[] = 'unit_of_work:clear';
    }
}

final readonly class TransactionalRunnerFailingUnitOfWork implements UnitOfWork
{
    public function __construct(private TransactionalRunnerLog $log) {}

    public function hasPendingChanges(): bool
    {
        return true;
    }

    public function flush(): void
    {
        $this->log->entries[] = 'unit_of_work:flush';

        throw new \RuntimeException('flush failed');
    }

    public function clear(): void
    {
        $this->log->entries[] = 'unit_of_work:clear';
    }
}

final readonly class TransactionalRunnerTransactionManager implements TransactionManager
{
    public function __construct(private TransactionalRunnerLog $log) {}

    public function transactional(callable $callback): mixed
    {
        $this->log->entries[] = 'transaction:begin';

        try {
            $result = $callback();
            $this->log->entries[] = 'transaction:commit';

            return $result;
        } catch (\Throwable $e) {
            $this->log->entries[] = 'transaction:rollback';

            throw $e;
        }
    }
}
