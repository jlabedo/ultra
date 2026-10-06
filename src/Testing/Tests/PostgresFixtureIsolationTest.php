<?php

declare(strict_types=1);

namespace Ultra\Testing\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Testing\PostgresConnectionSupport;

/** @internal */
#[CoversClass(PostgresConnectionSupport::class)]
final class PostgresFixtureIsolationTest extends TestCase
{
    public function test_named_shared_connections_preserve_independent_transaction_and_lock_ownership(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify Postgres fixture isolation.');
        }
        $shared = PostgresConnectionSupport::sharedConnection($dsn);
        $other = PostgresConnectionSupport::sharedConnection($dsn, self::class);
        $lock = random_int(1, PHP_INT_MAX - 1);
        try {
            $otherPid = $other->fetchOne('SELECT pg_backend_pid()');
            self::assertNotSame($shared->fetchOne('SELECT pg_backend_pid()'), $otherPid);
            $shared->beginTransaction();
            $shared->executeStatement('SELECT pg_advisory_xact_lock(?)', [$lock]);
            $other->beginTransaction();
            $other->beginTransaction();
            $other->executeStatement('SELECT pg_advisory_lock(?)', [$lock + 1]);
            self::assertFalse((bool) $shared->fetchOne('SELECT pg_try_advisory_lock(?)', [$lock + 1]));

            PostgresConnectionSupport::resetSharedConnection($other);

            self::assertSame($other, PostgresConnectionSupport::sharedConnection($dsn, self::class));
            self::assertSame($otherPid, $other->fetchOne('SELECT pg_backend_pid()'));
            self::assertSame(0, $other->getTransactionNestingLevel());
            self::assertSame(1, $shared->getTransactionNestingLevel());
            self::assertFalse((bool) $other->fetchOne('SELECT pg_try_advisory_lock(?)', [$lock]));
            self::assertTrue((bool) $shared->fetchOne('SELECT pg_try_advisory_lock(?)', [$lock + 1]));
            self::assertSame($shared, PostgresConnectionSupport::sharedConnection($dsn));
        } finally {
            PostgresConnectionSupport::resetSharedConnection($other);
            PostgresConnectionSupport::resetSharedConnection($shared);
        }
    }

    public function test_reusing_a_shared_connection_clears_nested_transactions_and_session_locks(): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to verify Postgres fixture isolation.');
        }
        $shared = PostgresConnectionSupport::sharedConnection($dsn);
        $other = PostgresConnectionSupport::connection($dsn);
        $table = 'ultra_testing_'.bin2hex(random_bytes(8));
        $lock = random_int(1, PHP_INT_MAX);
        try {
            self::assertSame($shared, PostgresConnectionSupport::sharedConnection($dsn));
            self::assertNotSame($shared->fetchOne('SELECT pg_backend_pid()'), $other->fetchOne('SELECT pg_backend_pid()'));
            $shared->executeStatement('CREATE TEMPORARY TABLE '.$table.' (value INTEGER)');
            $shared->beginTransaction();
            $shared->executeStatement('INSERT INTO '.$table.' VALUES (1)');
            $shared->beginTransaction();
            $shared->executeStatement('INSERT INTO '.$table.' VALUES (2)');
            $shared->executeStatement('SELECT pg_advisory_lock(?)', [$lock]);
            self::assertFalse((bool) $other->fetchOne('SELECT pg_try_advisory_lock(?)', [$lock]));

            PostgresConnectionSupport::resetSharedConnection($shared);

            self::assertSame(0, $shared->getTransactionNestingLevel());
            self::assertSame(0, (int) $shared->fetchOne('SELECT count(*) FROM '.$table));
            self::assertTrue((bool) $other->fetchOne('SELECT pg_try_advisory_lock(?)', [$lock]));
            self::assertSame($shared, PostgresConnectionSupport::sharedConnection($dsn));
        } finally {
            PostgresConnectionSupport::resetSharedConnection($shared);
            $other->executeStatement('SELECT pg_advisory_unlock_all()');
            $other->close();
            $shared->executeStatement('DROP TABLE IF EXISTS '.$table);
        }
    }
}
