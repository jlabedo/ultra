<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Ultra\EventStore\EventStore;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivityRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\Tests\Harness\ProjectionCoordinationHarness;


require_once __DIR__.'/Harness/ProjectionCoordinationHarness.php';

/**
 * @internal
 */
#[CoversNothing]
#[Group('slow')]
final class PostgresProjectionProcessHarnessTest extends PostgresProjectionTestCase
{
    public function test_manual_harness_command_creates_schema_runs_backfill_and_leaves_tables_available(): void
    {
        $dsn = $this->postgresDsn();
        $connection = ProjectionCoordinationHarness::connection($dsn);
        $eventStorePrefix = 'projection_manual_event_'.bin2hex(random_bytes(4)).'_';
        $projectionPrefix = 'projection_manual_projection_'.bin2hex(random_bytes(4)).'_';
        $application = ProjectionCoordinationHarness::createApplication(
            $dsn,
            $eventStorePrefix,
            $projectionPrefix,
            __DIR__.'/Harness/projection-coordination-harness',
        );

        try {
            [$exitCode, $output] = $this->runHarnessCommand($application, [
                'command' => 'harness:projection-test',
                '--events' => '12',
                '--workers' => '3',
                '--batch-size' => '2',
                '--max-cycles' => '64',
                '--reset-schema' => true,
            ]);

            self::assertSame(0, $exitCode, $output);
            self::assertStringContainsString('Schema ready.', $output);
            self::assertStringContainsString('Projected rows: 12', $output);
            self::assertStringContainsString('Pending buckets: 0', $output);

            self::assertSame(12, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %s',
                ProjectionCoordinationHarness::readModelTable($projectionPrefix),
            )));
            self::assertSame(12, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %sevents',
                $eventStorePrefix,
            )));
            self::assertSame(0, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %sbucket_activity WHERE counter > 0',
                $projectionPrefix,
            )));

            [$resetCode, $resetOutput] = $this->runHarnessCommand($application, [
                'command' => 'harness:projection-reset',
            ]);

            self::assertSame(0, $resetCode, $resetOutput);
            self::assertStringContainsString('Projection "harness_course_names" was reset.', $resetOutput);
            self::assertSame([], (new PostgresProjectionSegmentRepository($connection, $projectionPrefix))
                ->listSegments(ProjectionCoordinationHarness::PROJECTION_ID));
            self::assertNull($connection->fetchOne(sprintf(
                "SELECT to_regclass('%s')",
                ProjectionCoordinationHarness::readModelTable($projectionPrefix),
            )));

            [$secondBackfillCode, $secondBackfillOutput] = $this->runHarnessCommand($application, [
                'command' => 'harness:projection-backfill',
                'workers' => '3',
                '--batch-size' => '2',
                '--max-cycles' => '64',
            ]);

            self::assertSame(0, $secondBackfillCode, $secondBackfillOutput);
            self::assertSame(12, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %s',
                ProjectionCoordinationHarness::readModelTable($projectionPrefix),
            )));
            self::assertSame(0, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %s WHERE counter > 0',
                $projectionPrefix.'bucket_activity',
            )));
        } finally {
            \Ultra\Testing\PostgresTestSupport::resetSharedConnection($connection);

            foreach (array_reverse(ProjectionCoordinationHarness::schemas($eventStorePrefix, $projectionPrefix)) as $schema) {
                foreach ($schema->dropStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }

            $connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s',
                ProjectionCoordinationHarness::readModelTable($projectionPrefix),
            ));
        }
    }

    public function test_console_backfill_command_spawns_process_workers_until_seeded_events_are_projected(): void
    {
        $this->withPostgresProjectionCoordinatorRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            PostgresProjectionBucketActivityRepository $activityRepository,
            PostgresProjectionCoordinatorLeaseRepository $leaseRepository,
            string $eventStorePrefix,
            string $projectionPrefix,
        ): void {
            $application = ProjectionCoordinationHarness::createApplication(
                $this->postgresDsn(),
                $eventStorePrefix,
                $projectionPrefix,
                __DIR__.'/Harness/projection-coordination-harness',
            );

            [$seedCode, $seedOutput] = $this->runHarnessCommand($application, [
                'command' => 'harness:projection-seed',
                'event-count' => '32',
            ]);

            self::assertSame(0, $seedCode, $seedOutput);
            self::assertStringContainsString('Seeded 32 projection events in batches of 1000.', $seedOutput);
            self::assertNotSame([], $activityRepository->affectedBuckets());

            [$backfillCode, $backfillOutput] = $this->runHarnessCommand($application, [
                'command' => 'harness:projection-backfill',
                'workers' => '4',
                '--batch-size' => '3',
                '--max-cycles' => '128',
            ]);

            self::assertSame(0, $backfillCode, $backfillOutput);
            self::assertGreaterThanOrEqual(1, substr_count($backfillOutput, 'worker=process-worker-'));
            self::assertSame([], $activityRepository->affectedBuckets());

            $rows = $connection->fetchAllAssociative(sprintf(
                'SELECT course_id, name FROM %s ORDER BY course_id ASC',
                ProjectionCoordinationHarness::readModelTable($projectionPrefix),
            ));

            self::assertCount(32, $rows);
            self::assertSame(['course_id' => 'course-001', 'name' => 'Course 001'], $rows[0]);
            self::assertSame(['course_id' => 'course-032', 'name' => 'Course 032'], $rows[31]);

            $segments = $segmentRepository->listSegments(ProjectionCoordinationHarness::PROJECTION_ID);

            self::assertGreaterThanOrEqual(4, count($segments));
            foreach ($segments as $segment) {
                self::assertNull($segment->workerId);
            }
        });
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: string}
     */
    private function runHarnessCommand(Application $application, array $input): array
    {
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput($input), $output);

        return [$exitCode, $output->fetch()];
    }
}
