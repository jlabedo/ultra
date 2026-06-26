<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Ultra\Projection_Postgres\Tests\Harness\ProjectionBackfillHarness;
use Ultra\Testing\PostgresTestSupport;

/**
 * @internal
 *
 * @coversNothing
 */
final class PostgresProjectionBackfillHarnessTest extends PostgresProjectionTestCase
{
    public function test_harness_seeds_events_and_backfills_with_process_workers(): void
    {
        $connection = PostgresTestSupport::sharedConnection($this->postgresDsn());
        $eventStorePrefix = PostgresTestSupport::uniquePrefix();
        $projectionPrefix = 'ultra_projection_harness_'.bin2hex(random_bytes(4)).'_';
        $schemas = ProjectionBackfillHarness::schemas($eventStorePrefix, $projectionPrefix);
        $consolePath = dirname(__DIR__).'/Tests/Harness/projection-coordination-harness';

        try {
            $application = ProjectionBackfillHarness::createApplication(
                $this->postgresDsn(),
                $eventStorePrefix,
                $projectionPrefix,
                $consolePath,
            );
            $output = new BufferedOutput();

            $exitCode = $application->find('harness:projection-test')->run(new ArrayInput([
                '--events' => '16',
                '--workers' => '4',
                '--batch-size' => '2',
                '--reset-schema' => true,
            ]), $output);

            self::assertSame(Command::SUCCESS, $exitCode, $output->fetch());
            self::assertSame(16, (int) $connection->fetchOne(sprintf(
                'SELECT count(*) FROM %s',
                ProjectionBackfillHarness::readModelTable($projectionPrefix),
            )));
        } finally {
            PostgresTestSupport::resetSharedConnection($connection);

            $connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s',
                ProjectionBackfillHarness::readModelTable($projectionPrefix),
            ));

            foreach (array_reverse($schemas) as $schema) {
                foreach ($schema->dropStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }
        }
    }
}

