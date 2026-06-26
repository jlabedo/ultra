<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Backfill\ProjectionBackfillExecutorInterface;
use Ultra\Projection\Backfill\ProjectionBackfillJob;
use Ultra\Projection\Backfill\ProjectionBackfillProcessResult;
use Ultra\Projection\Bundle\Command\BackfillProjectionCommand;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentStore;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionTopology;
use Ultra\Projection\Tests\Support\BackfillProjection;

/**
 * @internal
 */
#[CoversClass(BackfillProjectionCommand::class)]
final class BackfillProjectionCommandTest extends TestCase
{
    public function test_dispatches_planned_jobs_to_the_executor(): void
    {
        $runtime = new ProjectionRuntime(new BackfillProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentStore();
        $runner = new ProjectionRunner($eventStore, $segments);
        $topology = new ProjectionTopology($eventStore, $segments, $segments);

        $executor = new class implements ProjectionBackfillExecutorInterface {
            /** @var list<ProjectionBackfillJob> */
            public array $jobs = [];

            public function run(array $jobs): array
            {
                $this->jobs = $jobs;

                return array_map(
                    static fn (ProjectionBackfillJob $job): ProjectionBackfillProcessResult => new ProjectionBackfillProcessResult($job, 0, '', ''),
                    $jobs,
                );
            }
        };

        $tester = new CommandTester(new BackfillProjectionCommand(
            new ProjectionCatalog($runtime),
            $runner,
            $topology,
            $executor,
        ));

        self::assertSame(0, $tester->execute([
            'projection-id' => 'backfill_projection',
            '--workers' => '2',
            '--limit' => '10',
        ]));
        self::assertSame([0, 128], array_map(static fn (ProjectionBackfillJob $job): int => $job->bucketFrom, $executor->jobs));
        self::assertStringContainsString('Backfilling "backfill_projection" with 2 process worker(s).', $tester->getDisplay());
    }

    public function test_returns_failure_when_a_child_process_fails(): void
    {
        $runtime = new ProjectionRuntime(new BackfillProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentStore();
        $runner = new ProjectionRunner($eventStore, $segments);
        $topology = new ProjectionTopology($eventStore, $segments, $segments);
        $job = new ProjectionBackfillJob('backfill_projection', 0, 'worker-0', 100);

        $executor = new class ($job) implements ProjectionBackfillExecutorInterface {
            public function __construct(private readonly ProjectionBackfillJob $job) {}

            public function run(array $jobs): array
            {
                return [
                    new ProjectionBackfillProcessResult($this->job, 1, '', 'boom'),
                ];
            }
        };

        $tester = new CommandTester(new BackfillProjectionCommand(
            new ProjectionCatalog($runtime),
            $runner,
            $topology,
            $executor,
        ));

        self::assertSame(1, $tester->execute(['projection-id' => 'backfill_projection']));
        self::assertStringContainsString('boom', $tester->getDisplay());
    }
}
