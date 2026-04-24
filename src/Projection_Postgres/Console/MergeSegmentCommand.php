<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;

#[AsCommand(
    name: 'projection:segment:merge',
    description: 'Mark two sibling segments for merge into their parent range.',
)]
final class MergeSegmentCommand extends Command
{
    public function __construct(private readonly PostgresProjectionSegmentRepository $repository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projection-id', InputArgument::REQUIRED, 'The projection identifier (e.g. course_names).')
            ->addArgument('bucket-from', InputArgument::REQUIRED, 'The bucket_from of the parent range (e.g. 0).')
            ->addArgument('bucket-to', InputArgument::REQUIRED, 'The bucket_to of the parent range (e.g. 255).')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectionId = (string) $input->getArgument('projection-id');
        $bucketFrom = (int) $input->getArgument('bucket-from');
        $bucketTo = (int) $input->getArgument('bucket-to');

        $this->repository->requestMerge($projectionId, $bucketFrom, $bucketTo);

        $segments = $this->repository->listSegments($projectionId);
        $output->writeln(sprintf('<info>Segments for "%s" after merge request:</info>', $projectionId));
        foreach ($segments as $seg) {
            $pending = $seg['pendingOperation'] ? sprintf(' [pending: %s]', $seg['pendingOperation']) : '';
            $output->writeln(sprintf('  [%d-%d] %s%s', $seg['bucketFrom'], $seg['bucketTo'], $seg['status'], $pending));
        }

        return Command::SUCCESS;
    }
}
