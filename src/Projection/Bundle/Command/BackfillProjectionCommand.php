<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Projection\Backfill\ProjectionBackfillExecutorInterface;
use Ultra\Projection\Backfill\ProjectionBackfillJob;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionSegment;
use Ultra\Projection\ProjectionTopology;
use Ultra\Projection\ProjectionRunner;

#[AsCommand(
    name: 'projection:backfill',
    description: 'Backfill a projection with one process worker per canonical segment.',
)]
final class BackfillProjectionCommand extends Command
{
    public function __construct(
        private readonly ProjectionCatalog $catalog,
        private readonly ProjectionRunner $runner,
        private readonly ProjectionTopology $topology,
        private readonly ProjectionBackfillExecutorInterface $executor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projection-id', InputArgument::REQUIRED, 'The projection identifier.')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'Number of process workers and target canonical segments.', 1)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum events loaded per segment batch.', 100)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectionId = (string) $input->getArgument('projection-id');
        $workers = (int) $input->getOption('workers');
        $limit = (int) $input->getOption('limit');
        $runtime = $this->catalog->runtime($projectionId);

        $this->runner->ensureInitialized($runtime);
        $segments = $this->topology->reshapeToCanonicalSegments($runtime, $workers, 'projection-backfill', $limit);
        $jobs = array_map(
            static fn (ProjectionSegment $segment): ProjectionBackfillJob => new ProjectionBackfillJob(
                projectionId: $projectionId,
                bucketFrom: $segment->bucketFrom,
                workerId: sprintf('projection-backfill:%s:%d', $projectionId, $segment->bucketFrom),
                limit: $limit,
            ),
            $segments,
        );
        $output->writeln(sprintf('Backfilling "%s" with %d process worker(s).', $projectionId, count($jobs)));

        $processes = $this->executor->run($jobs);
        $successful = true;
        foreach ($processes as $process) {
            $output->writeln(sprintf(
                '[%s] bucket %d exited with %d',
                $process->job->workerId,
                $process->job->bucketFrom,
                $process->exitCode,
            ));
            if ('' !== $process->output) {
                $output->write($process->output);
            }
            if ('' !== $process->errorOutput) {
                $output->write($process->errorOutput);
            }
            if (0 !== $process->exitCode) {
                $successful = false;
            }
        }

        return $successful ? Command::SUCCESS : Command::FAILURE;
    }
}
