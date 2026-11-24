<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
final class RunMigrationCommand extends Command
{
    public const array DESCRIPTIONS = [
        'ultra:schema:migrate' => 'Apply pending PostgreSQL migrations',
        'ultra:schema:status' => 'Show the PostgreSQL migration journal and pending migrations',
        'ultra:schema:baseline' => 'Record the verified baseline for an existing PostgreSQL schema',
    ];

    public function __construct(
        string $name,
        private readonly ContainerInterface $connections,
        private readonly string $connectionId,
        private readonly string $directory,
        private readonly string $metadataTable,
    ) {
        parent::__construct($name);
        $this->setDescription(self::DESCRIPTIONS[$name]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->connections->has($this->connectionId)
            ? $this->connections->get($this->connectionId)
            : null;
        if (!$connection instanceof Connection) {
            throw new \LogicException(sprintf(
                'Configure ultra.schema.connection with the ID of a Doctrine DBAL connection service. "%s" is unavailable or is not a DBAL connection.',
                $this->connectionId,
            ));
        }
        $runner = new PostgresMigrationRunner($connection, $this->directory, $this->metadataTable);

        if ('ultra:schema:baseline' === $this->getName()) {
            $output->writeln($runner->baseline() ? 'Recorded 000000-baseline.' : '000000-baseline already recorded.');
        } elseif ('ultra:schema:status' === $this->getName()) {
            $status = $runner->status();
            if ([] === $status) {
                $output->writeln('No migrations in the versioned history.');
            }
            foreach ($status as $migration) {
                $output->writeln(
                    sprintf(
                        '%s  %s%s',
                        $migration['id'],
                        $migration['status'],
                        null === $migration['executedAt'] ? '' : ' at ' . $migration['executedAt'],
                    ),
                    OutputInterface::OUTPUT_RAW,
                );
            }
        } else {
            $applied = $runner->migrate();
            if ([] === $applied) {
                $output->writeln('No pending migrations.');
            }
            foreach ($applied as $id) {
                $output->writeln(sprintf('Applied %s.', $id), OutputInterface::OUTPUT_RAW);
            }
        }

        return Command::SUCCESS;
    }
}
