<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\Testing\PostgresConnectionSupport;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class PersistenceLockingTest extends RowSetTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function competingWrites(): iterable
    {
        yield 'checked row versus update' => ['present', 'update'];
        yield 'absent row versus insert' => ['absent', 'insert'];
        yield 'absent row versus upsert' => ['absent', 'upsert'];
    }

    #[DataProvider('competingWrites')]
    public function test_a_flushed_observation_blocks_competing_writes_until_its_transaction_ends(string $expectation, string $operation): void
    {
        $fixture = $this->fixture('postgres');
        $connection = $fixture->connection();
        if ('present' === $expectation) {
            $fixture->seed(['id' => 'row', 'status' => 'pending']);
            $fixture->rows->withKey('row')->expectColumnValue('status', 'pending');
        } else {
            $fixture->rows->withKey('row')->expectAbsent();
        }
        $other = PostgresConnectionSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
        $competing = new MappedRowSet(new PostgresMappedRowSetAdapter($other, $fixture->tableName));
        if ('update' === $operation) {
            $competing->withKey('row')->setColumn('status', 'paid');
        } else {
            $competing->withKey('row')->{$operation}()->setColumn('status', 'paid');
        }
        $connection->beginTransaction();
        try {
            $fixture->rows->flush();
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
            try {
                $competing->flush();
                self::fail('The competing write must wait for the observation transaction.');
            } catch (DriverException $exception) {
                self::assertStringContainsString('lock timeout', $exception->getMessage());
            } finally {
                $other->rollBack();
            }
            $connection->commit();
            $other->transactional(fn () => $competing->flush());
            self::assertSame(['status' => 'paid'], $fixture->stored('row', ['status']));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }
            $other->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function transactionScopedObservations(): iterable
    {
        yield 'absence' => ['absence'];
        yield 'membership' => ['membership'];
    }

    #[DataProvider('transactionScopedObservations')]
    public function test_postgres_observation_locks_require_an_active_transaction(string $expectation): void
    {
        $fixture = $this->fixture('postgres');
        if ('absence' === $expectation) {
            $fixture->rows->withKey('row')->expectAbsent();
        } else {
            $fixture->rows->expectSameRowKeys([]);
        }
        $this->expectException(\LogicException::class);
        $fixture->rows->flush();
    }
}
