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
use Ultra\Projection\ProjectionRebuilder;
use Ultra\Projection\ProjectionRunner;

#[AsCommand(
    name: 'ultra:projection:rebuild',
    description: 'Resume a managed synchronous projection reconstruction and optionally activate it.',
)]
final class RebuildProjectionCommand extends Command
{
    public function __construct(
        private readonly ProjectionCatalog $catalog,
        private readonly ProjectionRebuilder $rebuilder,
        private readonly ProjectionRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projection-id', InputArgument::REQUIRED, 'Managed synchronous projection identifier')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events per replay batch', 100)
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED, 'Replay worker identifier', 'rebuild')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Reset the read model and start a new generation')
            ->addOption(
                'activate',
                null,
                InputOption::VALUE_NONE,
                'Drain the remaining history under the barrier and activate',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = $this->catalog->runtime((string) $input->getArgument('projection-id'));
        if (\Ultra\Projection\ProjectionMode::Synchronous !== $runtime->definition()->mode) {
            throw new \LogicException('This command requires ProjectionMode::Synchronous.');
        }
        $batchSize = (int) $input->getOption('batch-size');
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be at least 1.');
        }
        if ($input->getOption('reset')) {
            $this->runner->deleteProjection($runtime);
        }
        $processed = $this->rebuilder->catchUp($runtime, (string) $input->getOption('worker-id'), $batchSize);
        if ($input->getOption('activate')) {
            $processed += $this->rebuilder->activate($runtime, $batchSize);
        }
        $output->writeln(sprintf(
            'Projection "%s": processed %d events%s.',
            $runtime->projectionId(),
            $processed,
            $input->getOption('activate') ? ', live' : '',
        ));

        return Command::SUCCESS;
    }
}
