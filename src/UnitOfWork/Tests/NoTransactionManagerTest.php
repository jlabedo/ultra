<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\UnitOfWork\NoTransactionManager;
use Ultra\UnitOfWork\TransactionManager;

#[CoversClass(NoTransactionManager::class)]
final class NoTransactionManagerTest extends TestCase
{
    public function test_it_returns_the_callback_result(): void
    {
        $manager = new NoTransactionManager();

        self::assertSame('result', $manager->transactional(static fn (): string => 'result'));
    }

    public function test_it_implements_transaction_manager(): void
    {
        self::assertInstanceOf(TransactionManager::class, new NoTransactionManager());
    }
}
