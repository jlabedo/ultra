<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\Coordination\InMemory\InMemoryDirtyKeyTracker;
use Ultra\Coordination\Tracking\DirtyKeyTracker;

/**
 * @internal
 *
 * @coversNothing
 */
final class InMemoryDirtyKeyTrackerTest extends TestCase
{
    public function test_bump_coalesces_and_pending_orders_by_counter(): void
    {
        $tracker = $this->tracker();
        $tracker->bump('subject', 's1');
        $tracker->bump('subject', 's1');
        $tracker->bump('subject', 's2');
        $tracker->bump('capability', 'c1');

        $pending = $tracker->pending();

        self::assertSame('s1', $pending[0]->key);
        self::assertSame(2, $pending[0]->counter);
        self::assertCount(3, $pending);
    }

    public function test_pending_for_channel_scopes_to_one_channel(): void
    {
        $tracker = $this->tracker();
        $tracker->bump('subject', 's1');
        $tracker->bump('capability', 'c1');

        $subjects = $tracker->pendingForChannel('subject');

        self::assertCount(1, $subjects);
        self::assertSame('s1', $subjects[0]->key);
        self::assertSame('subject', $subjects[0]->channel);
    }

    public function test_drain_seen_clears_observed_counter(): void
    {
        $tracker = $this->tracker();
        $tracker->bump('subject', 's1');
        $tracker->bump('subject', 's1');

        $tracker->drainSeen('subject', ['s1' => 2]);

        self::assertSame([], $tracker->pendingForChannel('subject'));
    }

    public function test_drain_seen_is_lost_update_safe(): void
    {
        $tracker = $this->tracker();
        $tracker->bump('subject', 's1');
        $tracker->bump('subject', 's1');

        // Observe the counter (2), then a concurrent producer bumps again.
        $seen = ['s1' => 2];
        $tracker->bump('subject', 's1'); // counter now 3

        $tracker->drainSeen('subject', $seen); // subtracts only 2

        $pending = $tracker->pendingForChannel('subject');
        self::assertCount(1, $pending);
        self::assertSame(1, $pending[0]->counter);
    }

    private function tracker(): DirtyKeyTracker
    {
        return new InMemoryDirtyKeyTracker();
    }
}
