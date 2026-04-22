<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivityRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;

final class PostgresProjectionCoordinationTest extends PostgresProjectionTestCase
{
    public function test_postgres_segment_repository_can_claim_the_segment_containing_a_bucket(): void
    {
        $this->withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
            $segments = new PostgresProjectionSegmentRepository($connection, $projectionPrefix);
            $segments->ensureInitialized('courses');
            $segments->splitSegment('courses', 0);
            $segments->reconcilePendingOperations('courses');

            $segment = $segments->claimSegmentForBucket('courses', 200, 'worker-1');

            self::assertNotNull($segment);
            self::assertSame(128, $segment->bucketFrom);
            self::assertSame(255, $segment->bucketTo);
        });
    }

    public function test_postgres_bucket_activity_repository_stores_counters_and_acknowledgements(): void
    {
        $this->withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
            $activity = new PostgresProjectionBucketActivityRepository($connection, $projectionPrefix);

            $activity->bump('courses', 42);
            $activity->bump('courses', 42);
            $activity->bump('other_courses', 3);

            $pending = $activity->pendingBuckets('courses');
            $affectedBuckets = $activity->affectedBuckets();

            self::assertCount(1, $pending);
            self::assertSame(2, $pending[0]->counter);
            self::assertSame(0, $pending[0]->acknowledgedCounter);
            self::assertCount(2, $affectedBuckets);
            self::assertSame('courses', $affectedBuckets[0]->projectionId);
            self::assertSame('other_courses', $affectedBuckets[1]->projectionId);

            $activity->acknowledge('courses', 42, 2);
            $activity->acknowledge('other_courses', 3, 1);

            self::assertSame([], $activity->pendingBuckets('courses'));
            self::assertSame([], $activity->affectedBuckets());
        });
    }

    public function test_postgres_coordinator_lease_repository_debounces_wakeups_until_a_coordinator_acquires_the_lease(): void
    {
        $this->withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
            $leases = new PostgresProjectionCoordinatorLeaseRepository($connection, $projectionPrefix);

            self::assertTrue($leases->markWakeEnqueued(30));
            self::assertFalse($leases->markWakeEnqueued(30));
            self::assertTrue($leases->acquire('coordinator-1'));
            self::assertTrue($leases->hasLiveCoordinator());
            self::assertFalse($leases->markWakeEnqueued(30));
        });
    }
}
