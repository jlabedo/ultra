<?php

declare(strict_types=1);

namespace App;

use App\Event\OrderPlaced;
use App\Infrastructure\OrderProjection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\EventBus\EventBusInterface;
use Ultra\EventBus_Compiler\EventBusCompilerPass;

#[AsCommand(name: 'app:run')]
final readonly class RunEventBusCommand
{
    public function __construct(
        private EventBusInterface $eventBus,
        private OrderProjection $orders,
    ) {}

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Publishing events with Ultra Event Bus');
        $output->writeln('-------------------------------------');
        $output->writeln(sprintf('ultra/event-bus-compiler : %s', class_exists(EventBusCompilerPass::class) ? 'Yes' : 'No'));

        $this->eventBus->publish(new OrderPlaced('order-1', 1500, 'user-1'));
        $success = ['order-1'] === $this->orders->handledOrderIds;

        $output->writeln(sprintf('Success : %s', $success ? 'Yes' : 'No'));

        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
