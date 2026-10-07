<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;

#[AsCommand(name: 'ultra:projection:run', description: 'Run a projection worker loop.')]
final class ProjectionWorkerCommand extends Command
{
    public function __construct(
        private readonly ProjectionCoordinator $coordinator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED, 'Unique worker identifier', null)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events processed per cycle', 100)
            ->addOption('max-cycles', null, InputOption::VALUE_REQUIRED, 'Coordinator cycles per run', 32);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = $input->getOption('worker-id');
        assert(null === $workerId || is_string($workerId), 'The worker-id option is a string when supplied.');
        $workerId ??= (string) gethostname() . '-' . (string) getmypid();
        $batchSize = (int) $input->getOption('batch-size');
        $maxCycles = (int) $input->getOption('max-cycles');

        $output->writeln(sprintf('<info>Starting projection worker "%s"</info>', $workerId));

        while (true) {
            $result = $this->coordinator->run($workerId, $batchSize, $maxCycles);

            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    '[%s] lease=%s processed=%d acknowledged=%d activations=%d',
                    date('H:i:s'),
                    $result->acquiredLease ? 'yes' : 'no',
                    $result->processedEvents,
                    $result->acknowledgedBuckets,
                    $result->requestedWorkerActivations,
                ));
            }

            if (!$result->acquiredLease || 0 === $result->processedEvents && 0 === $result->acknowledgedBuckets) {
                usleep(100_000);
            }
        }
    }
}
