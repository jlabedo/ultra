<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\Coordination\InMemory\InMemoryCoordinatorLease;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/**
 * @internal
 *
 * @coversNothing
 */
final class InMemoryCoordinatorLeaseTest extends TestCase
{
    public function test_only_one_worker_holds_the_lease(): void
    {
        $lease = new InMemoryCoordinatorLease(new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z')));

        self::assertTrue($lease->acquire('global', 'w1'));
        self::assertFalse($lease->acquire('global', 'w2'));
        self::assertTrue($lease->hasLiveCoordinator('global'));
    }

    public function test_lease_is_acquirable_after_expiry(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $lease = new InMemoryCoordinatorLease($clock);

        self::assertTrue($lease->acquire('global', 'w1', new Duration(seconds: 60)));
        $clock->setNow(new \DateTimeImmutable('2026-01-01T00:02:00Z'));

        self::assertFalse($lease->hasLiveCoordinator('global'));
        self::assertTrue($lease->acquire('global', 'w2'));
    }

    public function test_release_frees_the_lease(): void
    {
        $lease = new InMemoryCoordinatorLease(new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z')));

        self::assertTrue($lease->acquire('global', 'w1'));
        $lease->release('global', 'w1');

        self::assertTrue($lease->acquire('global', 'w2'));
    }

    public function test_keys_are_independent(): void
    {
        $lease = new InMemoryCoordinatorLease(new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z')));

        self::assertTrue($lease->acquire('a', 'w1'));
        self::assertTrue($lease->acquire('b', 'w1'));
    }

    public function test_wake_is_debounced_within_ttl_and_suppressed_while_live(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $lease = new InMemoryCoordinatorLease($clock);

        // No coordinator yet: first wake wins, second within TTL is suppressed.
        self::assertTrue($lease->markWakeEnqueued('global', new Duration(seconds: 30)));
        self::assertFalse($lease->markWakeEnqueued('global', new Duration(seconds: 30)));

        // Past the TTL, a wake is allowed again.
        $clock->setNow(new \DateTimeImmutable('2026-01-01T00:01:00Z'));
        self::assertTrue($lease->markWakeEnqueued('global', new Duration(seconds: 30)));

        // While a coordinator is live, no wake is needed.
        self::assertTrue($lease->acquire('global', 'w1'));
        self::assertFalse($lease->markWakeEnqueued('global', new Duration(seconds: 30)));
    }
}
