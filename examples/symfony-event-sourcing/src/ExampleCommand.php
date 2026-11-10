<?php

declare(strict_types=1);

namespace App;

use App\Command\PlaceOrder;
use App\Command\ShipOrder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;

#[AsCommand(name: 'app:orders', description: 'Place, ship, project, list or rebuild orders; initialize with schema.')]
final class ExampleCommand extends Command
{
    public function __construct(
        private readonly MediatorInterface $mediator,
        private readonly Schema $schema,
        private readonly Connection $connection,
        private readonly ProjectionCoordinator $coordinator,
        private readonly ProjectionRunner $projections,
        private readonly ProjectionCatalog $catalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'schema, place, ship, project, list or rebuild')
            ->addArgument('id', InputArgument::OPTIONAL, 'Order identifier')
            ->addArgument('customer', InputArgument::OPTIONAL, 'Customer name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $id = (string) $input->getArgument('id');
        match ($action) {
            'schema' => $this->schema->create(),
            'place' => $this->mediator->send(new PlaceOrder($id, (string) $input->getArgument('customer'))),
            'ship' => $this->mediator->send(new ShipOrder($id)),
            'project' => $this->coordinator->run('cli-' . (string) getmypid()),
            'rebuild' => $this->projections->replayFromBeginning($this->catalog->runtime('order_summary')),
            'list' => null,
            default => throw new \InvalidArgumentException('Unknown action: ' . (string) $action),
        };

        $output->writeln(json_encode(
            $this->connection->fetchAllAssociative('SELECT * FROM order_summary ORDER BY id'),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        ));

        return Command::SUCCESS;
    }
}
