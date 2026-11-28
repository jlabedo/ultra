<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Schema\SchemaDefinition;

/** @internal */
final class GenerateMigrationCommand extends Command
{
    /** @param list<array{service: \Closure(): object, methods: list<string>}> $providers */
    public function __construct(
        string $name,
        private readonly ?string $compiledSchema = null,
        private readonly ?string $historyDirectory = null,
        private readonly array $providers = [],
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
        $fragments = [$schema];
        foreach ($this->providers as $provider) {
            $service = $provider['service']();
            foreach ($provider['methods'] as $name) {
                $method = [$service, $name];
                if (!is_callable($method) || !($fragment = $method()) instanceof SchemaDefinition) {
                    throw new \LogicException(sprintf(
                        'Schema fragment "%s::%s" must return %s.',
                        $service::class,
                        $name,
                        SchemaDefinition::class,
                    ));
                }
                $fragments[] = $fragment;
            }
        }

        $history = $this->historyDirectory ?? (string) $input->getArgument('history-directory');
        $migration = new MigrationGenerator($history)->generate(
            SchemaDefinition::merge(...$fragments),
            (string) $input->getArgument('name'),
            true === $input->getOption('allow-destructive'),
        );

        $output->writeln(
            null === $migration
                ? 'No schema changes.'
                : sprintf(
                    'Generated %s/%s/migration.sql and migration.lock, and updated the root snapshot.',
                    $history,
                    $migration,
                ),
            OutputInterface::OUTPUT_RAW,
        );

        return Command::SUCCESS;
    }
}
