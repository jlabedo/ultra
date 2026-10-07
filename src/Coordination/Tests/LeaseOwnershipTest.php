<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Coordination\Tests\Support\CoordinationTestCase;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/** @internal */
#[CoversNamespace('Ultra\Coordination')]
#[CoversNamespace('Ultra\Coordination_Postgres')]
final class LeaseOwnershipTest extends CoordinationTestCase
{
    #[DataProvider('backends')]
    public function test_only_the_holder_can_release_a_lease_and_keys_are_independent(string $backend): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $lease = $this->lease($backend, $clock);
        static::assertFalse($lease->hasLiveCoordinator('a'));
        $lease->renew('missing', 'w1');
        $lease->release('missing', 'w1');
        static::assertFalse($lease->hasLiveCoordinator('missing'));
        static::assertTrue($lease->acquire('a', 'w1'));
        static::assertTrue($lease->acquire('b', 'w1'));
        static::assertFalse($lease->acquire('a', 'w1'));
        static::assertFalse($lease->acquire('a', 'w2'));
        $lease->release('a', 'w2');
        static::assertTrue($lease->hasLiveCoordinator('a'));
        $lease->release('a', 'w1');
        static::assertFalse($lease->hasLiveCoordinator('a'));
        static::assertTrue($lease->acquire('a', 'w2'));
        static::assertFalse($lease->acquire('b', 'w2'));
    }

    #[DataProvider('backends')]
    public function test_renewal_extends_ownership_until_expiry_and_stale_workers_cannot_change_the_successor(string $backend): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $lease = $this->lease($backend, $clock);
        static::assertTrue($lease->acquire('a', 'w1'));
        static::assertTrue($lease->acquire('unrenewed', 'w1'));
        $clock->sleep(new Duration(seconds: 60));
        static::assertTrue($lease->hasLiveCoordinator('a'));
        static::assertFalse($lease->acquire('a', 'w2'));
        $lease->renew('a', 'w1');
        $clock->sleep(new Duration(seconds: 1));
        static::assertFalse($lease->hasLiveCoordinator('unrenewed'));
        $clock->sleep(new Duration(seconds: 59));
        static::assertTrue($lease->hasLiveCoordinator('a'));
        $clock->sleep(new Duration(seconds: 1));
        static::assertTrue($lease->acquire('a', 'w2', new Duration(seconds: 10)));
        $clock->sleep(new Duration(seconds: 5));
        $lease->renew('a', 'w2', new Duration(seconds: 10));
        $lease->release('a', 'w1');
        $lease->renew('a', 'w1', new Duration(seconds: 100));
        static::assertTrue($lease->hasLiveCoordinator('a'));
        $clock->sleep(new Duration(seconds: 11));
        static::assertFalse($lease->hasLiveCoordinator('a'));
        static::assertTrue($lease->acquire('a', 'w3'));
    }
}
