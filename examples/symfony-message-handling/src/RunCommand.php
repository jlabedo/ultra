<?php

declare(strict_types=1);

namespace App;

use App\EventBus\Event\OrderPlaced;
use App\EventBus\Infrastructure\OrderProjection;
use App\Mediator\Infrastructure\OrderRepository;
use App\Mediator\Message\PlaceOrder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling_Compiler\MessageRouterCompilerPass;

#[AsCommand(name: 'app:run')]
final readonly class RunCommand
{
    public function __construct(
        private MediatorInterface $mediator,
        private EventBusInterface $eventBus,
        private OrderRepository $orderRepository,
        private OrderProjection $orders,
    ) {}

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf(
            'ultra/message-handling-compiler : %s',
            class_exists(MessageRouterCompilerPass::class) ? 'Yes' : 'No',
        ));
        $output->writeln('');

        $eventBus = $this->runEventBus($input, $output);
        $output->writeln('');
        $mediator = $this->runMediator($input, $output);

        return $eventBus | $mediator;
    }

    private function runMediator(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Dispatching commands with Ultra Mediator');
        $output->writeln('---------------------------------------');

        $result = $this->mediator->send(new PlaceOrder('order-1', 1500, 'user-1'));
        $success = 'ORDER-1' === $result && ['order-1'] === $this->orderRepository->savedOrderIds;

        $output->writeln(sprintf('Success : %s', $success ? 'Yes' : 'No'));

        return $success ? Command::SUCCESS : Command::FAILURE;
    }

    private function runEventBus(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Publishing events with Ultra Event Bus');
        $output->writeln('---------------------------------------');

        $this->eventBus->publish(new OrderPlaced('order-1', 1500, 'user-1'));
        $success = ['order-1'] === $this->orders->handledOrderIds;

        $output->writeln(sprintf('Success : %s', $success ? 'Yes' : 'No'));

        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
