<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use PHPUnit\Framework\TestCase;
use Ultra\Testing\PostgresTestSupport;
use Ultra\UnitOfWork\DoctrineTransactionManager;

final class DoctrineTransactionManagerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run transaction manager tests.');
        }
        $this->connection = PostgresTestSupport::connection($dsn);
        $this->connection->executeStatement('CREATE TEMP TABLE transaction_manager_values (value TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    public function test_success_commits_and_returns_the_callback_result(): void
    {
        $result = new DoctrineTransactionManager($this->connection)->transactional(function (): int {
            self::assertTrue($this->connection->isTransactionActive());
            $this->connection->insert('transaction_manager_values', ['value' => 'committed']);
            return 42;
        });
        self::assertSame(42, $result);
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame(['committed'], $this->connection->fetchFirstColumn('SELECT value FROM transaction_manager_values'));
    }

    public function test_failure_rolls_back_and_propagates_the_original_exception(): void
    {
        $failure = new \RuntimeException('Failed work');
        try {
            new DoctrineTransactionManager($this->connection)->transactional(function () use ($failure): void {
                $this->connection->insert('transaction_manager_values', ['value' => 'rolled back']);
                throw $failure;
            });
            self::fail('The exception must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
            self::assertFalse($this->connection->isTransactionActive());
            self::assertSame([], $this->connection->fetchFirstColumn('SELECT value FROM transaction_manager_values'));
        }
    }

    public function test_configured_isolation_is_applied_and_restored_after_commit_and_rollback(): void
    {
        $this->connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $manager = new DoctrineTransactionManager($this->connection, TransactionIsolationLevel::READ_COMMITTED);
        $manager->transactional(function (): void {
            self::assertSame('read committed', $this->connection->fetchOne('SHOW transaction_isolation'));
        });
        self::assertSame('repeatable read', $this->connection->fetchOne('SHOW transaction_isolation'));
        try {
            $manager->transactional(function (): void {
                self::assertSame('read committed', $this->connection->fetchOne('SHOW transaction_isolation'));
                throw new \RuntimeException('Rollback');
            });
            self::fail('The exception must propagate.');
        } catch (\RuntimeException) {
            self::assertSame(TransactionIsolationLevel::REPEATABLE_READ, $this->connection->getTransactionIsolation());
            self::assertSame('repeatable read', $this->connection->fetchOne('SHOW transaction_isolation'));
        }
    }

    public function test_default_isolation_respects_the_connection_configuration(): void
    {
        $this->connection->setTransactionIsolation(TransactionIsolationLevel::SERIALIZABLE);
        new DoctrineTransactionManager($this->connection)->transactional(function (): void {
            self::assertSame('serializable', $this->connection->fetchOne('SHOW transaction_isolation'));
        });
        self::assertSame('serializable', $this->connection->fetchOne('SHOW transaction_isolation'));
    }

    public function test_external_transaction_is_rejected_without_running_work_or_changing_it(): void
    {
        $this->connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $this->connection->beginTransaction();
        try {
            new DoctrineTransactionManager($this->connection, TransactionIsolationLevel::READ_COMMITTED)->transactional(
                static fn () => self::fail('Work must not run inside an external transaction.'),
            );
            self::fail('An external transaction must be rejected.');
        } catch (\LogicException) {
            self::assertTrue($this->connection->isTransactionActive());
            self::assertSame('repeatable read', $this->connection->fetchOne('SHOW transaction_isolation'));
        } finally {
            $this->connection->rollBack();
        }
    }
}
