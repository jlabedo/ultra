<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\StateStore\GuardLockMode;
use Ultra\StateStore\InMemoryGuardLockAdapter;

/** @internal */
final class InMemoryGuardLockAdapterTest extends TestCase
{
    public static function modes(): iterable
    {
        yield 'shared' => [GuardLockMode::SHARED];
        yield 'exclusive' => [GuardLockMode::EXCLUSIVE];
    }

    #[DataProvider('modes')]
    public function test_acquisition_requires_an_active_transaction(GuardLockMode $mode): void
    {
        $adapter = new InMemoryGuardLockAdapter(new InMemoryRowDatabase());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('active transaction');
        $adapter->acquire('usage', 'company', $mode);
    }
}
