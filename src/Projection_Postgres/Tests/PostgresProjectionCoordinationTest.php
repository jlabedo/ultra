<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Time\Duration;

/**
 * @internal
 *
 * @coversNothing
 */
final class PostgresProjectionCoordinationTest extends PostgresProjectionTestCase
{
    private const string KEY = ProjectionCoordinator::COORDINATOR_KEY;

    public function test_postgres_segment_repository_can_claim_a_segment_for_a_worker(): void
    {
        $this->withPostgresProjectionCoordination(function (
            Connection $connection,
            PostgresProjectionSegmentRepository $segments,
        ): void {
            $segments->ensureInitialized('courses');
            $segments->splitSegment('courses', 0);

            $segment = $segments->claimSegment('courses', 128, 'worker-1');

            self::assertNotNull($segment);
            self::assertSame(128, $segment->bucketFrom);
            self::assertSame(255, $segment->bucketTo);
        });
    }

    public function test_postgres_dirty_key_tracker_stores_pending_counters_and_drains_seen_values(): void
    {
        $this->withPostgresProjectionCoordination(function (
            Connection $connection,
            PostgresProjectionSegmentRepository $segments,
            ProjectionActivity $activity,
        ): void {
            $activity->bump('courses', 42);
            $activity->bump('courses', 42);
            $activity->bump('courses', 50);
            $activity->bump('other_courses', 3);

            $pending = $activity->pendingInSegment('courses', 0, 255);
            $segmentPending = $activity->pendingInSegment('courses', 40, 45);
            $affectedBuckets = $activity->affected();

            self::assertCount(2, $pending);
            self::assertSame(2, $pending[0]->counter);
            self::assertCount(1, $segmentPending);
            self::assertSame(42, (int) $segmentPending[0]->key);
            self::assertCount(3, $affectedBuckets);
            self::assertSame('courses', $affectedBuckets[0]->channel);
            self::assertSame('courses', $affectedBuckets[1]->channel);
            self::assertSame('other_courses', $affectedBuckets[2]->channel);

            $seenCounter = $segmentPending[0]->counter;
            $activity->bump('courses', 42);
            self::assertSame(1, $activity->drainSeen('courses', [42 => $seenCounter]));

            $remainingSegmentPending = $activity->pendingInSegment('courses', 40, 45);

            self::assertCount(1, $remainingSegmentPending);
            self::assertSame(1, $remainingSegmentPending[0]->counter);
            self::assertSame(2, $activity->drainSeen('courses', [42 => 1, 50 => 1]));
            self::assertSame(1, $activity->drainSeen('other_courses', [3 => 1]));
            self::assertSame([], $activity->affected());
        });
    }

    public function test_postgres_coordinator_lease_repository_debounces_wakeups_until_a_coordinator_acquires_the_lease(): void
    {
        $this->withPostgresProjectionCoordination(function (
            Connection $connection,
            PostgresProjectionSegmentRepository $segments,
            ProjectionActivity $activity,
            CoordinatorLease $leases,
        ): void {
            self::assertTrue($leases->markWakeEnqueued(self::KEY, Duration::seconds(30)));
            self::assertFalse($leases->markWakeEnqueued(self::KEY, Duration::seconds(30)));
            self::assertTrue($leases->acquire(self::KEY, 'coordinator-1'));
            self::assertTrue($leases->hasLiveCoordinator(self::KEY));
            self::assertFalse($leases->markWakeEnqueued(self::KEY, Duration::seconds(30)));
        }, $this->staticClock('2024-01-01T00:00:00+00:00'));
    }

    public function test_postgres_coordinator_lease_expiry_follows_the_injected_clock(): void
    {
        $clock = $this->staticClock('2024-01-01T00:00:00+00:00');

        $this->withPostgresProjectionCoordination(function (
            Connection $connection,
            PostgresProjectionSegmentRepository $segments,
            ProjectionActivity $activity,
            CoordinatorLease $leases,
        ) use ($clock): void {
            self::assertTrue($leases->acquire(self::KEY, 'coordinator-1', Duration::seconds(30)));
            self::assertTrue($leases->hasLiveCoordinator(self::KEY));

            $clock->sleep(Duration::seconds(31));

            self::assertFalse($leases->hasLiveCoordinator(self::KEY));
            self::assertTrue($leases->acquire(self::KEY, 'coordinator-2', Duration::seconds(30)));
        }, $clock);
    }
}
