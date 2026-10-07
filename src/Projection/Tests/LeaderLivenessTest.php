<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\Projection\InMemory\InMemoryProjectionCoordinatorLeaseRepository;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class LeaderLivenessTest extends ProjectionTestCase
{
    public function test_expired_lease_can_be_reacquired_after_advancing_the_injected_clock(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = new InMemoryProjectionCoordinatorLeaseRepository($clock);

        static::assertTrue($repository->acquire('worker-1', Duration::seconds(30)));
        static::assertTrue($repository->hasLiveCoordinator());

        $clock->sleep(Duration::seconds(31));

        static::assertFalse($repository->hasLiveCoordinator());
        static::assertTrue($repository->acquire('worker-2', Duration::seconds(30)));
    }

    public function test_wake_enqueued_ttl_expires_against_the_injected_clock(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = new InMemoryProjectionCoordinatorLeaseRepository($clock);

        static::assertTrue($repository->markWakeEnqueued(Duration::seconds(30)));
        static::assertFalse($repository->markWakeEnqueued(Duration::seconds(30)));

        $clock->sleep(Duration::seconds(31));

        static::assertTrue($repository->markWakeEnqueued(Duration::seconds(30)));
    }
}
