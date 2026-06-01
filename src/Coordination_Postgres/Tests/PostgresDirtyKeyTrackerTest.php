<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\Testing\PostgresTestSupport;

/**
 * @internal
 *
 * @coversNothing
 */
final class PostgresDirtyKeyTrackerTest extends TestCase
{
    public function test_bump_pending_and_drain_seen_round_trip(): void
    {
        $this->withTracker(function (PostgresDirtyKeyTracker $tracker): void {
            $tracker->bump('subject', 's1');
            $tracker->bump('subject', 's1');
            $tracker->bump('subject', 's2');
            $tracker->bump('capability', 'c1');

            $subjects = $tracker->pendingForChannel('subject');
            self::assertCount(2, $subjects);
            self::assertSame('s1', $subjects[0]->key);
            self::assertSame(2, $subjects[0]->counter);

            $all = $tracker->pending();
            self::assertCount(3, $all);
            self::assertSame(2, $all[0]->counter);
        });
    }

    public function test_drain_seen_is_lost_update_safe(): void
    {
        $this->withTracker(function (PostgresDirtyKeyTracker $tracker): void {
            $tracker->bump('subject', 's1');
            $tracker->bump('subject', 's1');

            // Observe 2, then a concurrent bump lands.
            $seen = ['s1' => 2];
            $tracker->bump('subject', 's1');

            $drained = $tracker->drainSeen('subject', $seen);
            self::assertSame(1, $drained);

            $pending = $tracker->pendingForChannel('subject');
            self::assertCount(1, $pending);
            self::assertSame(1, $pending[0]->counter);
        });
    }

    public function test_keys_with_special_characters_are_handled(): void
    {
        $this->withTracker(function (PostgresDirtyKeyTracker $tracker): void {
            $weird = "id,with'quote\"and\\slash";
            $tracker->bump('subject', $weird);

            $pending = $tracker->pendingForChannel('subject');
            self::assertCount(1, $pending);
            self::assertSame($weird, $pending[0]->key);

            $tracker->drainSeen('subject', [$weird => 1]);
            self::assertSame([], $tracker->pendingForChannel('subject'));
        });
    }

    /**
     * @param callable(PostgresDirtyKeyTracker): void $callback
     */
    private function withTracker(callable $callback): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres coordination tests.');
        }

        $connection = PostgresTestSupport::sharedConnection($dsn);
        $table = 'coordination_dirty_keys_'.bin2hex(random_bytes(4));
        $schema = new PostgresDirtyKeyTrackerSchema($table);

        try {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $callback(new PostgresDirtyKeyTracker($connection, $table));
        } finally {
            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }
}
