<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;

#[AsCommand(
    name: 'projection:segment:list',
    description: 'List all segments for a projection.',
)]
final class ListSegmentsCommand extends Command
{
    public function __construct(private readonly ProjectionSegmentRepositoryInterface $repository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('projection-id', InputArgument::REQUIRED, 'The projection identifier (e.g. course_names).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectionId = (string) $input->getArgument('projection-id');
        $segments = $this->repository->listSegments($projectionId);

        if ([] === $segments) {
            $output->writeln(sprintf('<comment>No segments found for "%s".</comment>', $projectionId));

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['bucket_from', 'bucket_to', 'range_size', 'worker_id']);

        foreach ($segments as $segment) {
            $table->addRow([
                $segment->bucketFrom,
                $segment->bucketTo,
                $segment->bucketTo - $segment->bucketFrom + 1,
                $segment->workerId ?? '-',
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
