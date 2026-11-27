<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
final class ValidateMigrationCommand extends Command
{
    public function __construct(
        string $name,
        private readonly ?string $historyDirectory = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Validate the versioned migration history without connecting to PostgreSQL');
        if (null === $this->historyDirectory) {
            $this->addArgument(
                'history-directory',
                InputArgument::REQUIRED,
                'Directory containing the versioned migration history',
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $this->historyDirectory ?? (string) $input->getArgument('history-directory');
        new MigrationHistory($directory)->head();
        $output->writeln('Migration history is valid.');

        return Command::SUCCESS;
    }
}
