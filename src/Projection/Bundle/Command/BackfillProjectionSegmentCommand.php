<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;

#[AsCommand(
    name: 'projection:backfill:segment',
    description: 'Drain one projection segment until caught up.',
)]
final class BackfillProjectionSegmentCommand extends Command
{
    public function __construct(
        private readonly ProjectionCatalog $catalog,
        private readonly ProjectionRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projection-id', InputArgument::REQUIRED, 'The projection identifier.')
            ->addArgument('bucket-from', InputArgument::REQUIRED, 'The segment bucket_from value.')
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED, 'The worker id used to claim the segment.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum events loaded per batch.', 100)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectionId = (string) $input->getArgument('projection-id');
        $bucketFrom = (int) $input->getArgument('bucket-from');
        $workerId = (string) ($input->getOption('worker-id') ?? sprintf('projection-backfill:%s:%d', $projectionId, $bucketFrom));
        $limit = (int) $input->getOption('limit');

        if ('' === $workerId) {
            throw new \InvalidArgumentException('Backfill segment worker id must be non-empty.');
        }

        if ($limit <= 0) {
            throw new \InvalidArgumentException('Backfill segment limit must be positive.');
        }

        $runtime = $this->catalog->runtime($projectionId);
        $processed = 0;

        do {
            $result = $this->runner->backfillClaimedOnce($runtime, $projectionId, $bucketFrom, $workerId, $limit);
            if (!$result->claimedSegment) {
                $output->writeln(sprintf('Segment %d for projection "%s" could not be claimed.', $bucketFrom, $projectionId));

                return 2;
            }

            $processed += $result->processedEvents;
        } while (!$result->caughtUp);

        $output->writeln(sprintf('Segment %d for projection "%s" caught up after %d event(s).', $bucketFrom, $projectionId, $processed));

        return Command::SUCCESS;
    }
}

