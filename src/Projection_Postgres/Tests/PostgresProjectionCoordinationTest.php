<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivityRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Time\Duration;

/**
 * @internal
 *
 * @coversNothing
 */
final class PostgresProjectionCoordinationTest extends PostgresProjectionTestCase
{
    public function test_postgres_segment_repository_can_assign_and_claim_a_segment_for_a_worker(): void
    {
        $this->withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
            $segments = new PostgresProjectionSegmentRepository($connection, $projectionPrefix);
            $segments->ensureInitialized('courses');
            $segments->splitSegment('courses', 0);
            $segments->reconcilePendingOperations('courses');

            $assignment = $segments->assignSegment('courses', 128, 'worker-1');
            self::assertNotNull($assignment);
            $segment = $segments->claimAssignedSegment($assignment);

            self::assertNotNull($segment);
            self::assertSame(128, $segment->bucketFrom);
            self::assertSame(255, $segment->bucketTo);
        });
    }

    public function test_postgres_bucket_activity_repository_stores_pending_counters_and_decrements_seen_values(): void
    {
        $this->withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
            $activity = new PostgresProjectionBucketActivityRepository($connection, $projectionPrefix);

            $activity->bump('courses', 42);
            $activity->bump('courses', 42);
            $activity->bump('courses', 50);
            $activity->bump('other_courses', 3);

            $pending = $activity->pendingBucketsForSegment('courses', 0, 255);
            $segmentPending = $activity->pendingBucketsForSegment('courses', 40, 45);
            $affectedBuckets = $activity->affectedBuckets();

            self::assertCount(2, $pending);
            self::assertSame(2, $pending[0]->counter);
            self::assertCount(1, $segmentPending);
            self::assertSame(42, $segmentPending[0]->bucket);
            self::assertCount(3, $affectedBuckets);
            self::assertSame('courses', $affectedBuckets[0]->projectionId);
            self::assertSame('courses', $affectedBuckets[1]->projectionId);
            self::assertSame('other_courses', $affectedBuckets[2]->projectionId);

            $seenCounter = $segmentPending[0]->counter;
            $activity->bump('courses', 42);
            self::assertSame(1, $activity->decrementSeenBuckets('courses', [42 => $seenCounter]));

            $remainingSegmentPending = $activity->pendingBucketsForSegment('courses', 40, 45);

            self::assertCount(1, $remainingSegmentPending);
            self::assertSame(1, $remainingSegmentPending[0]->counter);
            self::assertSame(2, $activity->decrementSeenBuckets('courses', [42 => 1, 50 => 1]));
            self::assertSame(1, $activity->decrementSeenBuckets('other_courses', [3 => 1]));
            self::assertSame([], $activity->affectedBuckets());
        });
    }

    public function test_postgres_coordinator_lease_repository_debounces_wakeups_until_a_coordinator_acquires_the_lease(): void
    {
        $this->withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
            $clock = $this->staticClock('2024-01-01T00:00:00+00:00');
            $leases = new PostgresProjectionCoordinatorLeaseRepository($connection, $projectionPrefix, $clock);

            self::assertTrue($leases->markWakeEnqueued(Duration::seconds(30)));
            self::assertFalse($leases->markWakeEnqueued(Duration::seconds(30)));
            self::assertTrue($leases->acquire('coordinator-1'));
            self::assertTrue($leases->hasLiveCoordinator());
            self::assertFalse($leases->markWakeEnqueued(Duration::seconds(30)));
        });
    }

    public function test_postgres_coordinator_lease_expiry_follows_the_injected_clock(): void
    {
        $this->withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
            $clock = $this->staticClock('2024-01-01T00:00:00+00:00');
            $leases = new PostgresProjectionCoordinatorLeaseRepository($connection, $projectionPrefix, $clock);

            self::assertTrue($leases->acquire('coordinator-1', Duration::seconds(30)));
            self::assertTrue($leases->hasLiveCoordinator());

            $clock->sleep(Duration::seconds(31));

            self::assertFalse($leases->hasLiveCoordinator());
            self::assertTrue($leases->acquire('coordinator-2', Duration::seconds(30)));
        });
    }
}
