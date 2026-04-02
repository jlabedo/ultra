<?php

declare(strict_types=1);

namespace App;

use App\Infrastructure\OrderRepository;
use App\Message\PlaceOrder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Mediator_Compiler\MediatorCompilerPass;
use Ultra\MessageHandling\MediatorInterface;

#[AsCommand(name: 'app:run')]
final readonly class RunMediatorCommand
{
    public function __construct(
        private MediatorInterface $mediator,
        private OrderRepository $orders,
    ) {}

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Dispatching commands with Ultra Mediator');
        $output->writeln('---------------------------------------');
        $output->writeln(sprintf('ultra/mediator-compiler : %s', class_exists(MediatorCompilerPass::class) ? 'Yes' : 'No'));

        $result = $this->mediator->send(new PlaceOrder('order-1', 1500, 'user-1'));
        $success = 'ORDER-1' === $result && ['order-1'] === $this->orders->savedOrderIds;

        $output->writeln(sprintf('Success : %s', $success ? 'Yes' : 'No'));

        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
