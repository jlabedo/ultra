<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Projection\InMemory\InMemoryProjectionCoordinatorLeaseRepository;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/**
 * @internal
 */
#[CoversClass(InMemoryProjectionCoordinatorLeaseRepository::class)]
final class InMemoryProjectionCoordinatorLeaseRepositoryTest extends TestCase
{
    public function test_expired_lease_can_be_reacquired_after_advancing_the_injected_clock(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = new InMemoryProjectionCoordinatorLeaseRepository($clock);

        self::assertTrue($repository->acquire('worker-1', Duration::seconds(30)));
        self::assertTrue($repository->hasLiveCoordinator());

        $clock->advance(Duration::seconds(31));

        self::assertFalse($repository->hasLiveCoordinator());
        self::assertTrue($repository->acquire('worker-2', Duration::seconds(30)));
    }

    public function test_wake_enqueued_ttl_expires_against_the_injected_clock(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = new InMemoryProjectionCoordinatorLeaseRepository($clock);

        self::assertTrue($repository->markWakeEnqueued(Duration::seconds(30)));
        self::assertFalse($repository->markWakeEnqueued(Duration::seconds(30)));

        $clock->advance(Duration::seconds(31));

        self::assertTrue($repository->markWakeEnqueued(Duration::seconds(30)));
    }
}
