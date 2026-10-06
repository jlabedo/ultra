<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Tests\Support\ProjectionTestCase;
use Ultra\Projection\Tests\Support\TrackingProjection;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class SegmentOwnershipTest extends ProjectionTestCase
{
    #[DataProvider('backends')]
    public function test_only_first_creation_initializes_the_full_bucket_range(string $backend): void
    {
        $repository = $this->fixture($backend)->segments;

        self::assertTrue($repository->ensureInitialized('courses'));
        self::assertFalse($repository->ensureInitialized('courses'));
        $segments = $repository->listSegments('courses');
        self::assertCount(1, $segments);
        self::assertSame(0, $segments[0]->bucketFrom);
        self::assertSame(255, $segments[0]->bucketTo);
        self::assertNull($segments[0]->workerId);
    }

    #[DataProvider('backends')]
    public function test_competing_workers_cannot_claim_an_owned_segment(string $backend): void
    {
        $repository = $this->fixture($backend)->segments;
        $repository->ensureInitialized('courses');

        $claimed = $repository->claimSegment('courses', 0, 'worker-1');
        self::assertNotNull($claimed);
        self::assertSame('courses', $claimed->projectionId);
        self::assertSame(0, $claimed->bucketFrom);
        self::assertSame(255, $claimed->bucketTo);
        self::assertSame(0, $claimed->checkpoint->gapFloor->value);
        self::assertSame('worker-1', $claimed->workerId);
        self::assertNull($repository->claimSegment('courses', 0, 'worker-2'));
    }

    #[DataProvider('backends')]
    public function test_expired_segment_claims_become_available_to_another_worker(string $backend): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));
        $repository = $this->fixture($backend, $clock)->segments;
        $repository->ensureInitialized('courses');

        self::assertNotNull($repository->claimSegment('courses', 0, 'worker-1', Duration::seconds(30)));

        $clock->sleep(Duration::seconds(31));

        self::assertSame(1, $repository->recoverExpiredClaims('courses'));
        self::assertNotNull($repository->claimSegment('courses', 0, 'worker-2', Duration::seconds(30)));
    }

    #[DataProvider('backends')]
    public function test_a_foreign_worker_cannot_execute_an_existing_claim(string $backend): void
    {
        $runtime = new ProjectionRuntime(new TrackingProjection(), new UltraNormalizer());
        $fixture = $this->fixture($backend);
        $segments = $fixture->segments;
        $activity = $fixture->activity;
        $runner = $fixture->runner;

        $runner->ensureInitialized($runtime);
        self::assertNotNull($runner->claimSegment('tracking_projection', 0, 'worker-1'));

        $result = $runner->runClaimedOnce(
            $runtime,
            'tracking_projection',
            0,
            'worker-2',
        );

        self::assertFalse($result->claimedSegment);
        self::assertSame('worker-1', $segments->listSegments('tracking_projection')[0]->workerId);
    }
}
