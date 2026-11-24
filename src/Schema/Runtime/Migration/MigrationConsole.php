<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
final class MigrationConsole
{
    public static function create(): Application
    {
        $application = new Application('ultra-schema');
        $application->setCatchErrors(true);

        $application
            ->register('init')
            ->setDescription('Record the current schema as a baseline for an existing database')
            ->addArgument('schema', InputArgument::REQUIRED, 'PHP file returning a SchemaDefinition')
            ->addArgument(
                'history-directory',
                InputArgument::REQUIRED,
                'Directory containing the versioned migration history',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output): int {
                $schema = SchemaDefinitionFile::load((string) $input->getArgument('schema'));
                $history = (string) $input->getArgument('history-directory');
                $baseline = new MigrationGenerator($history)->initialize($schema);

                $output->writeln(
                    sprintf('Recorded schema baseline %s/%s/snapshot.json.', $history, $baseline),
                    OutputInterface::OUTPUT_RAW,
                );

                return Command::SUCCESS;
            });

        $application->addCommands([new GenerateMigrationCommand('generate')]);

        return $application;
    }
}
