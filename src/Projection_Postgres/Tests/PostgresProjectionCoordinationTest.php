<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivityRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivitySchema;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestUniquePrefix;

require_once __DIR__ . '/../../EventStore_Postgres/Tests/Support/PostgresTestSupport.php';

function withPostgresProjectionCoordination(callable $callback): void
{
    $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
    if (false === $dsn || '' === $dsn) {
        test()->markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres projection integration tests.');
    }

    $connection = postgresTestConnection($dsn);
    $projectionPrefix = 'ultra_projection_' . bin2hex(random_bytes(4)) . '_';
    $schemas = [
        new PostgresProjectionSegmentSchema($projectionPrefix),
        new PostgresProjectionCoordinatorSchema($projectionPrefix),
        new PostgresProjectionBucketActivitySchema($projectionPrefix),
    ];

    try {
        foreach ($schemas as $schema) {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        }

        $callback($connection, $projectionPrefix);
    } finally {
        foreach (array_reverse($schemas) as $schema) {
            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        }

        $connection->close();
    }
}

test('postgres segment repository can claim the segment containing a bucket', function (): void {
    withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
        $segments = new PostgresProjectionSegmentRepository($connection, $projectionPrefix);
        $segments->ensureInitialized('courses');
        $segments->splitSegment('courses', 0);

        $segment = $segments->claimSegmentForBucket('courses', 200, 'worker-1');

        expect($segment)->not->toBeNull()
            ->and($segment->bucketFrom)->toBe(128)
            ->and($segment->bucketTo)->toBe(255);
    });
});

test('postgres bucket activity repository stores counters and acknowledgements', function (): void {
    withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
        $activity = new PostgresProjectionBucketActivityRepository($connection, $projectionPrefix);

        $activity->bump('courses', 42);
        $activity->bump('courses', 42);

        $pending = $activity->pendingBuckets('courses');

        expect($pending)->toHaveCount(1)
            ->and($pending[0]->counter)->toBe(2)
            ->and($pending[0]->acknowledgedCounter)->toBe(0);

        $activity->acknowledge('courses', 42, 2);

        expect($activity->pendingBuckets('courses'))->toBe([]);
    });
});

test('postgres coordinator lease repository debounces wakeups until a coordinator acquires the lease', function (): void {
    withPostgresProjectionCoordination(function ($connection, string $projectionPrefix): void {
        $leases = new PostgresProjectionCoordinatorLeaseRepository($connection, $projectionPrefix);

        expect($leases->markWakeEnqueued('courses', 30))->toBeTrue()
            ->and($leases->markWakeEnqueued('courses', 30))->toBeFalse()
            ->and($leases->acquire('courses', 'coordinator-1'))->toBeTrue()
            ->and($leases->hasLiveCoordinator('courses'))->toBeTrue()
            ->and($leases->markWakeEnqueued('courses', 30))->toBeFalse();
    });
});
