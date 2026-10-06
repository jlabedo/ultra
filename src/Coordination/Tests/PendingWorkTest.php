<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Coordination\Tests\Support\CoordinationTestCase;
use Ultra\Coordination\Tracking\PendingKey;

/** @internal */
#[CoversNamespace('Ultra\Coordination')]
#[CoversNamespace('Ultra\Coordination_Postgres')]
final class PendingWorkTest extends CoordinationTestCase
{
    #[DataProvider('backends')]
    public function test_observations_coalesce_pairs_prioritize_busy_keys_and_limit_each_channel(string $backend): void
    {
        $tracker = $this->tracker($backend);
        self::assertSame([], $tracker->pending());
        self::assertSame([], $tracker->pendingForChannel('absent'));
        foreach ([['b', 'z'], ['a', 'z'], ['a', 'a'], ['a', 'busy'], ['a', 'busy']] as [$channel, $key]) {
            $tracker->bump($channel, $key);
        }
        $expected = [new PendingKey('a', 'busy', 2), new PendingKey('a', 'a', 1), new PendingKey('a', 'z', 1), new PendingKey('b', 'z', 1)];
        self::assertEquals($expected, $tracker->pending());
        self::assertEquals(array_slice($expected, 0, 2), $tracker->pending(2));
        self::assertEquals(array_slice($expected, 0, 3), $tracker->pendingForChannel('a'));
        self::assertEquals([$expected[0]], $tracker->pendingForChannel('a', 1));
        self::assertSame([], $tracker->pending(0));
        self::assertSame([], $tracker->pendingForChannel('a', 0));
        for ($key = 0; $key < 257; ++$key) {
            $tracker->bump('many', (string) $key);
        }
        self::assertCount(256, $tracker->pending());
        self::assertCount(256, $tracker->pendingForChannel('many'));
    }

    #[DataProvider('backends')]
    public function test_acknowledgement_subtracts_only_observed_work_and_preserves_other_channels(string $backend): void
    {
        $tracker = $this->tracker($backend);
        $key = "id,with'quote\"and\\slash";
        foreach ([['a', '42'], ['a', $key], ['a', $key], ['b', $key]] as [$channel, $id]) {
            $tracker->bump($channel, $id);
        }
        $seen = ['missing' => 3, $key => $tracker->pendingForChannel('a')[0]->counter, '42' => 1];
        $tracker->bump('a', $key);
        self::assertSame(2, $tracker->drainSeen('a', $seen));
        self::assertEquals([new PendingKey('a', $key, 1)], $tracker->pendingForChannel('a'));
        self::assertEquals([new PendingKey('b', $key, 1)], $tracker->pendingForChannel('b'));
        self::assertSame(0, $tracker->drainSeen('a', []));
        self::assertSame(0, $tracker->drainSeen('a', [$key => 0, 'missing' => 3, '42' => -1]));
        self::assertEquals([new PendingKey('a', $key, 1)], $tracker->pendingForChannel('a'));
        self::assertSame(1, $tracker->drainSeen('a', [$key => 10]));
        self::assertSame([], $tracker->pendingForChannel('a'));
        self::assertSame(0, $tracker->drainSeen('a', $seen));
        self::assertSame(0, $tracker->drainSeen('a', [$key => 10]));
        $tracker->bump('a', $key);
        self::assertEquals([new PendingKey('a', $key, 1)], $tracker->pendingForChannel('a'));
    }
}
