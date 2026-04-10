<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryRowDatabase;

/**
 * @internal
 */
#[CoversClass(InMemoryRowDatabase::class)]
final class InMemoryRowDatabaseTest extends TestCase
{
    public function test_commit_keeps_changes_applied_during_transaction(): void
    {
        $database = new InMemoryRowDatabase();
        $database->seedRow('users', 'id', [
            'id' => 'row-1',
            'status' => 'pending',
        ]);

        $database->beginTransaction();
        self::assertTrue($database->isTransactionActive());

        $row = $database->row('users', 'id', 'row-1');
        self::assertIsArray($row);

        $row['status'] = 'active';
        $database->writeRow('users', 'id', $row);
        $database->commit();

        self::assertSame([
            'id' => 'row-1',
            'status' => 'active',
        ], $database->row('users', 'id', 'row-1'));
        self::assertFalse($database->isTransactionActive());
    }

    public function test_rollback_restores_snapshot_taken_at_begin_transaction(): void
    {
        $database = new InMemoryRowDatabase();
        $database->seedRow('users', 'id', [
            'id' => 'row-1',
            'status' => 'pending',
        ]);

        $database->beginTransaction();
        self::assertTrue($database->isTransactionActive());

        $row = $database->row('users', 'id', 'row-1');
        self::assertIsArray($row);

        $row['status'] = 'active';
        $database->writeRow('users', 'id', $row);
        $database->rollBack();

        self::assertSame([
            'id' => 'row-1',
            'status' => 'pending',
        ], $database->row('users', 'id', 'row-1'));
        self::assertFalse($database->isTransactionActive());
    }

    public function test_rollback_without_active_transaction_throws(): void
    {
        $database = new InMemoryRowDatabase();

        $this->expectException(\LogicException::class);

        $database->rollBack();
    }

    public function test_commit_without_active_transaction_throws(): void
    {
        $database = new InMemoryRowDatabase();

        $this->expectException(\LogicException::class);

        $database->commit();
    }
}
