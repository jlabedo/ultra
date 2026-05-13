<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\UnitOfWork\ApplicationLifecycle;
use Ultra\UnitOfWork\NoOpTransactionManager;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkLifecycle;

final class RecordingTransactionManager implements TransactionManager
{
    private bool $active = false;

    /**
     * @param list<string> $calls
     */
    public function __construct(private array &$calls) {}

    public function begin(): void
    {
        $this->active = true;
        $this->calls[] = 'transaction:begin';
    }

    public function commit(): void
    {
        $this->active = false;
        $this->calls[] = 'transaction:commit';
    }

    public function rollback(): void
    {
        $this->active = false;
        $this->calls[] = 'transaction:rollback';
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}

final class LifecycleRecordingUnitOfWork implements UnitOfWork
{
    /**
     * @param list<string> $calls
     */
    public function __construct(
        private string $name,
        private array &$calls,
        private ?\Throwable $flushException = null,
    ) {}

    public function flush(): void
    {
        $this->calls[] = $this->name.':flush';

        if (null !== $this->flushException) {
            throw $this->flushException;
        }
    }

    public function clear(): void
    {
        $this->calls[] = $this->name.':clear';
    }
}

/**
 * @internal
 */
#[CoversClass(ApplicationLifecycle::class)]
#[CoversClass(NoOpTransactionManager::class)]
final class ApplicationLifecycleTest extends TestCase
{
    public function test_run_wraps_operation_in_transaction_and_unit_of_work_phases(): void
    {
        $calls = [];
        $transactionManager = new RecordingTransactionManager($calls);
        $lifecycle = $this->lifecycle($transactionManager, $calls);

        $result = $lifecycle->run(static function () use (&$calls): string {
            $calls[] = 'operation';

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame([
            'transaction:begin',
            'operation',
            'before:flush',
            'transaction:commit',
            'after:flush',
            'before:clear',
            'after:clear',
        ], $calls);
    }

    public function test_run_rolls_back_and_clears_when_operation_fails(): void
    {
        $calls = [];
        $transactionManager = new RecordingTransactionManager($calls);
        $lifecycle = $this->lifecycle($transactionManager, $calls);
        $exception = new \RuntimeException('operation failed');

        try {
            $lifecycle->run(static function () use (&$calls, $exception): never {
                $calls[] = 'operation';

                throw $exception;
            });
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }

        self::assertSame([
            'transaction:begin',
            'operation',
            'transaction:rollback',
            'before:clear',
            'after:clear',
        ], $calls);
    }

    public function test_run_clears_and_rethrows_when_after_commit_flush_fails(): void
    {
        $calls = [];
        $transactionManager = new RecordingTransactionManager($calls);
        $exception = new \RuntimeException('after commit failed');
        $lifecycle = new ApplicationLifecycle(
            $transactionManager,
            new UnitOfWorkLifecycle(
                new LifecycleRecordingUnitOfWork('before', $calls),
                new LifecycleRecordingUnitOfWork('after', $calls, $exception),
            ),
        );

        try {
            $lifecycle->run(static function () use (&$calls): void {
                $calls[] = 'operation';
            });
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }

        self::assertSame([
            'transaction:begin',
            'operation',
            'before:flush',
            'transaction:commit',
            'after:flush',
            'before:clear',
            'after:clear',
        ], $calls);
    }

    public function test_nested_run_joins_active_lifecycle(): void
    {
        $calls = [];
        $transactionManager = new RecordingTransactionManager($calls);
        $lifecycle = $this->lifecycle($transactionManager, $calls);

        $lifecycle->run(function () use (&$calls, $lifecycle): void {
            $calls[] = 'outer';
            $lifecycle->run(static function () use (&$calls): void {
                $calls[] = 'inner';
            });
        });

        self::assertSame([
            'transaction:begin',
            'outer',
            'inner',
            'before:flush',
            'transaction:commit',
            'after:flush',
            'before:clear',
            'after:clear',
        ], $calls);
    }

    public function test_no_op_transaction_manager_tracks_activity(): void
    {
        $manager = new NoOpTransactionManager();

        self::assertFalse($manager->isActive());
        $manager->begin();
        self::assertTrue($manager->isActive());
        $manager->commit();
        self::assertFalse($manager->isActive());

        $manager->begin();
        self::assertTrue($manager->isActive());
        $manager->rollback();
        self::assertFalse($manager->isActive());
    }

    /**
     * @param list<string> $calls
     */
    private function lifecycle(TransactionManager $transactionManager, array &$calls): ApplicationLifecycle
    {
        return new ApplicationLifecycle(
            $transactionManager,
            new UnitOfWorkLifecycle(
                new LifecycleRecordingUnitOfWork('before', $calls),
                new LifecycleRecordingUnitOfWork('after', $calls),
            ),
        );
    }
}
