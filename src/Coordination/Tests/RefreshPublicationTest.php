<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\TestWith;
use Ultra\Coordination\Refresh\RefreshStep;
use Ultra\Coordination\Refresh\RefreshWorker;
use Ultra\Coordination\Tests\Support\CoordinationTestCase;
use Ultra\Coordination\Tracking\PendingKey;
use Ultra\Coordination_Postgres\Refresh\PostgresRefreshPublication;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\Testing\PostgresConnectionSupport;

/** @internal */
#[CoversNamespace('Ultra\Coordination')]
#[CoversNamespace('Ultra\Coordination_Postgres')]
final class RefreshPublicationTest extends CoordinationTestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_only_one_of_two_prepared_refreshes_can_publish_the_same_revision(bool $publishedBefore): void
    {
        [$connection, $revisions, $markers] = $this->refreshTables();
        $publication = new PostgresRefreshPublication($connection, $revisions);
        $dirty = new PostgresDirtyKeyTracker($connection, $markers);
        $worker = new RefreshWorker($dirty, $publication);
        $otherConnection = PostgresConnectionSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
        $otherDirty = new PostgresDirtyKeyTracker($otherConnection, $markers);
        $other = new RefreshWorker($otherDirty, new PostgresRefreshPublication($otherConnection, $revisions));

        try {
            if ($publishedBefore) {
                $dirty->bump('account', 'seed');
                $worker->run(
                    'account',
                    static fn(PendingKey $key) => new RefreshStep(static function (): void {}, $key->counter),
                );
            }
            $dirty->bump('account', 'subject');

            $steps = $worker->run('account', static function (PendingKey $key) use ($other, $otherDirty): RefreshStep {
                static::assertSame('subject', $key->key);
                static::assertSame(1, $other->run(
                    'account',
                    static fn(PendingKey $otherKey) => new RefreshStep(static function (): void {}, $otherKey->counter),
                ));
                $otherDirty->bump('account', 'subject');

                return new RefreshStep(static function (): void {
                    static::fail('A losing refresh must not run its write.');
                }, $key->counter);
            });

            static::assertSame(0, $steps);
            static::assertSame($publishedBefore ? 2 : 1, $publication->revision('account'));
            static::assertSame(1, $dirty->pendingForChannel('account')[0]->counter);
        } finally {
            $otherConnection->close();
        }
    }

    public function test_published_pages_can_keep_work_pending_and_drain_only_the_count_they_cover(): void
    {
        [$connection, $revisions, $markers] = $this->refreshTables();
        $dirty = new PostgresDirtyKeyTracker($connection, $markers);
        $worker = new RefreshWorker($dirty, new PostgresRefreshPublication($connection, $revisions));
        $otherConnection = PostgresConnectionSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
        $otherDirty = new PostgresDirtyKeyTracker($otherConnection, $markers);
        $dirty->bump('account', 'qualification');
        $otherDirty->bump('account', 'qualification');

        try {
            $steps = $worker->run(
                'account',
                static function (PendingKey $key) use ($connection, $otherDirty): RefreshStep {
                    static::assertFalse($connection->isTransactionActive());
                    static::assertSame(2, $key->counter);
                    $otherDirty->bump('account', 'qualification');

                    return new RefreshStep(static function () use ($connection): void {
                        static::assertTrue($connection->isTransactionActive());
                        $connection->insert('refresh_result', ['page' => 'first']);
                    }, 0);
                },
                maxSteps: 1,
            );

            static::assertSame(1, $steps);
            static::assertSame(3, $dirty->pendingForChannel('account')[0]->counter);
            static::assertSame(['first'], $connection->fetchFirstColumn('SELECT page FROM refresh_result'));

            $steps = $worker->run(
                'account',
                static function (PendingKey $key) use ($connection, $otherDirty): RefreshStep {
                    static::assertSame(3, $key->counter);
                    $otherDirty->bump('account', 'qualification');

                    return new RefreshStep(static function () use ($connection): void {
                        $connection->insert('refresh_result', ['page' => 'last']);
                    }, 2);
                },
                maxSteps: 1,
            );

            static::assertSame(1, $steps);
            static::assertSame(2, $dirty->pendingForChannel('account')[0]->counter);
            static::assertSame(
                ['first', 'last'],
                $connection->fetchFirstColumn('SELECT page FROM refresh_result ORDER BY page'),
            );
        } finally {
            $otherConnection->close();
        }
    }

    public function test_failed_acknowledgement_rolls_back_the_result_and_revision(): void
    {
        [$connection, $revisions, $markers] = $this->refreshTables();
        $dirty = new PostgresDirtyKeyTracker($connection, $markers);
        $publication = new PostgresRefreshPublication($connection, $revisions);
        $worker = new RefreshWorker($dirty, $publication);
        $dirty->bump('account', 'subject');
        $connection->executeStatement('ALTER TABLE ' . $markers . ' ADD CHECK (counter > 0)');

        try {
            $worker->run('account', static fn(PendingKey $key) => new RefreshStep(static function () use (
                $connection,
            ): void {
                $connection->insert('refresh_result', ['page' => 'result']);
            }, $key->counter));
            static::fail('The database must reject acknowledgement.');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            static::assertSame('23514', $e->getSQLState());
        }

        static::assertSame([], $connection->fetchFirstColumn('SELECT page FROM refresh_result'));
        static::assertSame(0, $publication->revision('account'));
        static::assertSame(1, $dirty->pendingForChannel('account')[0]->counter);
    }

    #[TestWith([-1])]
    #[TestWith([2])]
    public function test_rejects_an_acknowledgement_outside_the_observed_count(int $acknowledge): void
    {
        [$connection, $revisions, $markers] = $this->refreshTables();
        $dirty = new PostgresDirtyKeyTracker($connection, $markers);
        $worker = new RefreshWorker($dirty, new PostgresRefreshPublication($connection, $revisions));
        $dirty->bump('account', 'subject');

        $this->expectException(\InvalidArgumentException::class);
        $worker->run('account', static fn(PendingKey $key) => new RefreshStep(static function (): void {
            static::fail('A step with an invalid acknowledgement must not run its write.');
        }, $acknowledge));
    }

    /** @return array{Connection, string, string} */
    private function refreshTables(): array
    {
        [$connection, $revisions] = $this->postgresTable('refresh_revisions');
        [, $markers] = $this->postgresTable('dirty_keys');
        $connection->executeStatement('CREATE TEMPORARY TABLE refresh_result (page TEXT NOT NULL)');

        return [$connection, $revisions, $markers];
    }
}
