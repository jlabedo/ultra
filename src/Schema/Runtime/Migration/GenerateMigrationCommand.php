<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
final class GenerateMigrationCommand extends Command
{
    public function __construct(
        string $name,
        private readonly ?string $compiledSchema = null,
        private readonly ?string $historyDirectory = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Generate a PostgreSQL migration from the last recorded schema');
        if (null === $this->compiledSchema) {
            $this->addArgument('schema', InputArgument::REQUIRED, 'PHP file returning a SchemaDefinition');
        }
        if (null === $this->historyDirectory) {
            $this->addArgument(
                'history-directory',
                InputArgument::REQUIRED,
                'Directory containing the versioned migration history',
            );
        }
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'Migration name in lowercase letters and digits separated by hyphens',
        )->addOption('allow-destructive', null, InputOption::VALUE_NONE, 'Allow dropping tables and columns');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schema = null === $this->compiledSchema
            ? SchemaDefinitionFile::load((string) $input->getArgument('schema'))
            : SchemaDefinitionData::decode(json_decode($this->compiledSchema, flags: JSON_THROW_ON_ERROR));
        $history = $this->historyDirectory ?? (string) $input->getArgument('history-directory');
        $migration = new MigrationGenerator($history)->generate(
            $schema,
            (string) $input->getArgument('name'),
            true === $input->getOption('allow-destructive'),
        );

        $output->writeln(
            null === $migration
                ? 'No schema changes.'
                : sprintf('Generated %s/%s/migration.sql and snapshot.json.', $history, $migration),
            OutputInterface::OUTPUT_RAW,
        );

        return Command::SUCCESS;
    }
}
