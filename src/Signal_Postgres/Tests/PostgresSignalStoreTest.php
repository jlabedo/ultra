<?php

declare(strict_types=1);

namespace Ultra\Signal_Postgres\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Signal\BucketRange;
use Ultra\Signal\SignalHint;
use Ultra\Signal_Postgres\PostgresSignalSchema;
use Ultra\Signal_Postgres\PostgresSignalStore;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

#[CoversClass(PostgresSignalStore::class)]
#[CoversClass(PostgresSignalSchema::class)]
final class PostgresSignalStoreTest extends TestCase
{
    public function test_signal_appends_duplicates_and_defaults_to_bucket_zero(): void
    {
        $this->withStore(function (Connection $connection, PostgresSignalStore $store, string $tablePrefix): void {
            $store->signal('projection_a', signalKey: 'product:1');
            $store->signal('projection_a', signalKey: 'product:1');

            $rows = $connection->fetchAllAssociative(sprintf(
                'SELECT queue_name, bucket, signal_key, insert_xid IS NOT NULL AS has_xid FROM %s ORDER BY id',
                $tablePrefix.'signals',
            ));

            self::assertCount(2, $rows);
            self::assertSame('projection_a', $rows[0]['queue_name']);
            self::assertSame(0, $rows[0]['bucket']);
            self::assertSame('product:1', $rows[0]['signal_key']);
            self::assertTrue((bool) $rows[0]['has_xid']);
        });
    }

    public function test_claim_hints_respects_visibility_delivery_timeout_and_limit(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $this->withStore(function (Connection $connection, PostgresSignalStore $store) use ($clock): void {
            $store->signal('projection_a', 10, 'visible');
            $store->signal('projection_a', 11, 'future', DatePoint::create(2026, 1, 1, 0, 0, 10));

            $first = $store->claimHints(['projection_a'], 'worker-1', 1, Duration::seconds(5));
            self::assertCount(1, $first);
            self::assertSame('visible', $first[0]->signalKey);

            self::assertSame([], $store->claimHints(['projection_a'], 'worker-2', 10, Duration::seconds(5)));

            $clock->sleep(Duration::seconds(5));
            $redelivered = $store->claimHints(['projection_a'], 'worker-2', 10, Duration::seconds(5));
            self::assertCount(1, $redelivered);
            self::assertSame($first[0]->id, $redelivered[0]->id);

            $clock->sleep(Duration::seconds(5));
            $available = $store->claimHints(['projection_a'], 'worker-3', 10, Duration::seconds(5));
            self::assertCount(2, $available);
            self::assertSame(['visible', 'future'], array_map(static fn (SignalHint $hint): ?string => $hint->signalKey, $available));

            self::assertSame(2, (int) $connection->fetchOne('SELECT 2'));
        }, $clock);
    }

    public function test_suppression_blocks_only_covered_signals_until_release_or_expiration(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $this->withStore(function (Connection $connection, PostgresSignalStore $store) use ($clock): void {
            $store->signal('projection_a', 100, 'covered');
            $store->signal('projection_a', 900, 'outside');

            $handle = $store->suppressDispatch(
                'projection_a',
                BucketRange::fromTo(0, 512),
                'worker-1',
                DatePoint::create(2026, 1, 1, 0, 1),
                'segment-1',
            );
            $store->suppressDispatch(
                'projection_a',
                BucketRange::fromTo(0, 512),
                'worker-2',
                DatePoint::create(2026, 1, 1, 0, 1),
            );

            $outside = $store->claimHints(['projection_a'], 'claimer', 10, Duration::seconds(5));
            self::assertCount(1, $outside);
            self::assertSame('outside', $outside[0]->signalKey);

            $store->releaseSuppression($handle);
            self::assertSame([], $store->claimHints(['projection_a'], 'claimer', 10, Duration::seconds(5)));

            $clock->sleep(Duration::minutes(1));
            $covered = $store->claimHints(['projection_a'], 'claimer', 10, Duration::seconds(5));
            self::assertCount(2, $covered);
            self::assertContains('covered', array_map(static fn (SignalHint $hint): ?string => $hint->signalKey, $covered));
        }, $clock);
    }

    public function test_ack_deletes_only_visible_signals_in_covered_bucket_range(): void
    {
        $this->withStore(function (Connection $connection, PostgresSignalStore $store, string $tablePrefix): void {
            $store->signal('projection_a', 100, 'a');
            $store->signal('projection_a', 200, 'b');
            $store->signal('projection_a', 900, 'c');
            $snapshot = $store->captureVisibilitySnapshot();

            self::assertSame(2, $store->ackVisibleInSnapshot('projection_a', BucketRange::fromTo(0, 512), $snapshot));

            $remaining = $connection->fetchFirstColumn(sprintf('SELECT signal_key FROM %s ORDER BY id', $tablePrefix.'signals'));
            self::assertSame(['c'], $remaining);
        });
    }

    public function test_ack_does_not_delete_signal_inserted_after_snapshot(): void
    {
        $this->withStore(function (Connection $connection, PostgresSignalStore $store, string $tablePrefix): void {
            $snapshot = $store->captureVisibilitySnapshot();
            $store->signal('projection_a', 100, 'after');

            self::assertSame(0, $store->ackVisibleInSnapshot('projection_a', BucketRange::fromTo(0, 512), $snapshot));
            self::assertSame(1, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $tablePrefix.'signals')));
        });
    }

    public function test_ack_does_not_delete_signal_inserted_by_transaction_in_progress_at_snapshot(): void
    {
        $dsn = $this->postgresDsn();
        $connection = PostgresTestSupport::sharedConnection($dsn);
        $other = PostgresTestSupport::connection($dsn);
        $tablePrefix = 'ultra_signal_'.bin2hex(random_bytes(4)).'_';
        $schema = new PostgresSignalSchema($tablePrefix);
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $store = new PostgresSignalStore($connection, $tablePrefix, $clock);
        $otherStore = new PostgresSignalStore($other, $tablePrefix, $clock);

        try {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $other->beginTransaction();
            $otherStore->signal('projection_a', 100, 'in-progress');

            $snapshot = $store->captureVisibilitySnapshot();

            $other->commit();

            self::assertSame(0, $store->ackVisibleInSnapshot('projection_a', BucketRange::fromTo(0, 512), $snapshot));
            self::assertSame(1, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $tablePrefix.'signals')));
        } finally {
            PostgresTestSupport::resetSharedConnection($connection);
            PostgresTestSupport::resetSharedConnection($other);

            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $other->close();
        }
    }

    public function test_ack_does_not_delete_delayed_signal_that_was_not_scheduled_visible_at_snapshot_time(): void
    {
        $this->withStore(function (Connection $connection, PostgresSignalStore $store, string $tablePrefix): void {
            $future = DatePoint::createFromInterface((new \DateTimeImmutable())->modify('+1 hour'));
            $store->signal('projection_a', 100, 'delayed', $future);
            $snapshot = $store->captureVisibilitySnapshot();

            self::assertSame(0, $store->ackVisibleInSnapshot('projection_a', BucketRange::fromTo(0, 512), $snapshot));
            self::assertSame(1, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $tablePrefix.'signals')));
        });
    }

    public function test_cleanup_removes_expired_suppressions_and_clears_expired_delivery_owner(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $this->withStore(function (Connection $connection, PostgresSignalStore $store, string $tablePrefix) use ($clock): void {
            $store->signal('projection_a', 100, 'a');
            $store->claimHints(['projection_a'], 'worker-1', 10, Duration::seconds(5));
            $store->suppressDispatch('projection_a', BucketRange::single(), 'worker-1', DatePoint::create(2026, 1, 1, 0, 0, 5));

            $clock->sleep(Duration::seconds(5));
            $store->cleanupExpired();

            self::assertSame(0, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $tablePrefix.'signal_suppressions')));
            self::assertSame(null, $connection->fetchOne(sprintf('SELECT delivery_owner FROM %s', $tablePrefix.'signals')));
        }, $clock);
    }

    /**
     * @param callable(Connection, PostgresSignalStore, string): void $callback
     */
    private function withStore(callable $callback, ?StatickClock $clock = null): void
    {
        $connection = PostgresTestSupport::sharedConnection($this->postgresDsn());
        $tablePrefix = 'ultra_signal_'.bin2hex(random_bytes(4)).'_';
        $schema = new PostgresSignalSchema($tablePrefix);
        $clock ??= new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        try {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $callback($connection, new PostgresSignalStore($connection, $tablePrefix, $clock), $tablePrefix);
        } finally {
            PostgresTestSupport::resetSharedConnection($connection);

            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }

    private function postgresDsn(): string
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres signal integration tests.');
        }

        return $dsn;
    }
}
