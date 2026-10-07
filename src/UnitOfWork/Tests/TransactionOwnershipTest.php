<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\UnitOfWork\DoctrineTransactionManager;
use Ultra\UnitOfWork\NoTransactionManager;
use Ultra\UnitOfWork\TransactionManager;

final class TransactionOwnershipTest extends TestCase
{
    private Connection $connection;

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    #[DataProvider('ownedTransactions')]
    public function test_owned_transactions_commit_or_rollback_and_restore_the_connection(
        bool $fails,
        ?TransactionIsolationLevel $override,
    ): void {
        $this->connect();
        $previous = TransactionIsolationLevel::REPEATABLE_READ;
        $this->connection->setTransactionIsolation($previous);
        $failure = new \RuntimeException('failed work');
        $result = null;
        $caught = null;
        try {
            $result = new DoctrineTransactionManager($this->connection, $override)->transactional(function () use (
                $fails,
                $override,
                $failure,
            ): int {
                self::assertTrue($this->connection->isTransactionActive());
                self::assertSame(
                    null === $override || TransactionIsolationLevel::REPEATABLE_READ === $override
                        ? 'repeatable read'
                        : 'read committed',
                    $this->connection->fetchOne('SHOW transaction_isolation'),
                );
                $this->connection->insert('unit_of_work_values', ['value' => 'work']);
                if ($fails) {
                    throw $failure;
                }

                return 42;
            });
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        static::assertSame($fails ? $failure : null, $caught);
        static::assertSame($fails ? null : 42, $result);
        static::assertFalse($this->connection->isTransactionActive());
        static::assertSame(
            $fails ? [] : ['work'],
            $this->connection->fetchFirstColumn('SELECT value FROM unit_of_work_values'),
        );
        static::assertSame($previous, $this->connection->getTransactionIsolation());
        static::assertSame('repeatable read', $this->connection->fetchOne('SHOW transaction_isolation'));
    }

    /** @return iterable<string, array{bool, ?TransactionIsolationLevel}> */
    public static function ownedTransactions(): iterable
    {
        foreach (['commit' => false, 'rollback' => true] as $outcome => $fails) {
            yield $outcome . ' with connection isolation' => [$fails, null];
            yield $outcome . ' with overridden isolation' => [$fails, TransactionIsolationLevel::READ_COMMITTED];
            yield $outcome . ' with identical isolation' => [$fails, TransactionIsolationLevel::REPEATABLE_READ];
        }
    }

    public function test_an_external_transaction_keeps_its_work_and_isolation_when_rejected(): void
    {
        $this->connect();
        $this->connection->setTransactionIsolation(TransactionIsolationLevel::SERIALIZABLE);
        $this->connection->beginTransaction();
        $this->connection->insert('unit_of_work_values', ['value' => 'external']);
        $called = false;
        try {
            new DoctrineTransactionManager(
                $this->connection,
                TransactionIsolationLevel::READ_COMMITTED,
            )->transactional(static function () use (&$called): void {
                $called = true;
            });
            static::fail('An external transaction must be rejected.');
        } catch (\LogicException) {
            static::assertFalse($called);
            static::assertTrue($this->connection->isTransactionActive());
            static::assertSame('serializable', $this->connection->fetchOne('SHOW transaction_isolation'));
            static::assertSame(
                ['external'],
                $this->connection->fetchFirstColumn('SELECT value FROM unit_of_work_values'),
            );
        } finally {
            $this->connection->rollBack();
        }
    }

    public function test_a_noop_manager_preserves_callback_results_and_failures(): void
    {
        $manager = new NoTransactionManager();
        $invoke = static fn(TransactionManager $transactions, callable $work): mixed => $transactions->transactional(
            $work,
        );
        $result = new \stdClass();
        static::assertSame($result, $invoke($manager, static fn(): object => $result));
        $failure = new \RuntimeException('failed work');
        try {
            $invoke($manager, static function () use ($failure): never {
                throw $failure;
            });
            static::fail('The original exception must propagate.');
        } catch (\RuntimeException $exception) {
            static::assertSame($failure, $exception);
        }
    }

    private function connect(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run transaction ownership tests.');
        }
        $this->connection = PostgresConnectionSupport::connection($dsn);
        $this->connection->executeStatement('CREATE TEMP TABLE unit_of_work_values (value TEXT NOT NULL)');
    }
}
