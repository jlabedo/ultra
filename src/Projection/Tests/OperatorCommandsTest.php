<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Bundle\Command\ListSegmentsCommand;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\Tests\Support\PostgresProjectionTestCase;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\Tests\Harness\ProjectionCoordinationHarness;
use Ultra\Testing\PostgresConnectionSupport;

/** @internal */
#[CoversNamespace('Ultra\\Projection')]
#[CoversNamespace('Ultra\\Projection_Postgres')]
final class OperatorCommandsTest extends PostgresProjectionTestCase
{
    public function test_lists_segments_from_the_projection_segment_repository(): void
    {
        $repository = new InMemoryProjectionSegmentRepository();
        $repository->ensureInitialized('course_names');
        $repository->splitSegment('course_names', 0);
        self::assertNotNull($repository->claimSegment('course_names', 128, 'worker-1'));

        $tester = new CommandTester(new ListSegmentsCommand($repository));

        self::assertSame(0, $tester->execute(['projection-id' => 'course_names']));

        $output = $tester->getDisplay();
        self::assertStringContainsString('bucket_from', $output);
        self::assertStringContainsString('bucket_to', $output);
        self::assertStringContainsString('0', $output);
        self::assertStringContainsString('127', $output);
        self::assertStringContainsString('128', $output);
        self::assertStringContainsString('255', $output);
        self::assertStringContainsString('worker-1', $output);
    }

    public function test_reports_when_a_projection_has_no_segments(): void
    {
        $tester = new CommandTester(new ListSegmentsCommand(new InMemoryProjectionSegmentRepository()));

        self::assertSame(0, $tester->execute(['projection-id' => 'course_names']));

        self::assertStringContainsString('No segments found for "course_names".', $tester->getDisplay());
    }

    #[Group('slow')]
    #[CoversNothing]
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
            __DIR__.'/Postgres/Harness/projection-coordination-harness',
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
            self::assertStringContainsString('Pending buckets: 12', $output);

            self::assertSame(12, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %s',
                ProjectionCoordinationHarness::readModelTable($projectionPrefix),
            )));
            self::assertSame(12, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %sevents',
                $eventStorePrefix,
            )));
            self::assertSame(12, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %sdirty_keys WHERE counter > 0',
                $projectionPrefix,
            )));

            [$resetCode, $resetOutput] = $this->runHarnessCommand($application, [
                'command' => 'harness:projection-reset',
            ]);

            self::assertSame(0, $resetCode, $resetOutput);
            self::assertStringContainsString('Projection "harness_course_names" was reset.', $resetOutput);
            self::assertSame([], new PostgresProjectionSegmentRepository($connection, $projectionPrefix)
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
            self::assertSame(12, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %s WHERE counter > 0',
                $projectionPrefix.'dirty_keys',
            )));
        } finally {
            PostgresConnectionSupport::resetSharedConnection($connection);

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

    #[Group('slow')]
    #[CoversNothing]
    public function test_console_backfill_command_spawns_process_workers_until_seeded_events_are_projected(): void
    {
        $this->withPostgresProjectionCoordinatorRuntime(function (
            Connection $connection,
            EventStore $store,
            PostgresProjectionSegmentRepository $segmentRepository,
            ProjectionRunner $runner,
            ProjectionActivity $activityRepository,
            CoordinatorLease $leaseRepository,
            string $eventStorePrefix,
            string $projectionPrefix,
        ): void {
            $application = ProjectionCoordinationHarness::createApplication(
                $this->postgresDsn(),
                $eventStorePrefix,
                $projectionPrefix,
                __DIR__.'/Postgres/Harness/projection-coordination-harness',
            );

            [$seedCode, $seedOutput] = $this->runHarnessCommand($application, [
                'command' => 'harness:projection-seed',
                'event-count' => '32',
            ]);

            self::assertSame(0, $seedCode, $seedOutput);
            self::assertStringContainsString('Seeded 32 projection events in batches of 1000.', $seedOutput);
            self::assertNotSame([], $activityRepository->affected());

            [$backfillCode, $backfillOutput] = $this->runHarnessCommand($application, [
                'command' => 'harness:projection-backfill',
                'workers' => '4',
                '--batch-size' => '3',
                '--max-cycles' => '128',
            ]);

            self::assertSame(0, $backfillCode, $backfillOutput);
            self::assertGreaterThanOrEqual(1, substr_count($backfillOutput, 'worker=process-backfill-worker-'));
            self::assertNotSame([], $activityRepository->affected());

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
