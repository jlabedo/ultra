<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Projection_Postgres\SegmentOperator;

#[AsCommand(
    name: 'projection:segment:split',
    description: 'Split a projection segment into two equal halves.',
)]
final class SplitSegmentCommand extends Command
{
    public function __construct(private readonly SegmentOperator $operator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('projection-id', InputArgument::REQUIRED, 'The projection identifier (e.g. course_names).')
            ->addArgument('bucket-from', InputArgument::REQUIRED, 'The bucket_from of the segment to split (e.g. 0).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectionId = (string) $input->getArgument('projection-id');
        $bucketFrom   = (int)    $input->getArgument('bucket-from');

        $message = $this->operator->split($projectionId, $bucketFrom);
        $output->writeln($message);

        $segments = $this->operator->listSegments($projectionId);
        $output->writeln('');
        $output->writeln(sprintf('<info>Current segments for "%s":</info>', $projectionId));
        foreach ($segments as $seg) {
            $pending = $seg['pendingOperation'] ? sprintf(' [pending: %s]', $seg['pendingOperation']) : '';
            $output->writeln(sprintf(
                '  [%d-%d] %s%s',
                $seg['bucketFrom'], $seg['bucketTo'], $seg['status'], $pending,
            ));
        }

        return Command::SUCCESS;
    }
}
