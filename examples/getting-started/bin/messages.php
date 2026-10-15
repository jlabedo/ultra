<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageHandlingExtension;

final readonly class Greet
{
    public function __construct(public string $name) {}
}

final class GreetingHandler
{
    #[Handles]
    public function greet(Greet $message): string
    {
        return 'Hello, ' . $message->name . '!';
    }
}

final class GreetingLog
{
    #[Before]
    public function before(object $message, MessageContext $context): void
    {
        echo "before\n";
    }

    #[After]
    public function after(object $message, MessageContext $context): void
    {
        echo "after\n";
    }
}

$container = new ContainerBuilder();
$container->setParameter('kernel.build_dir', dirname(__DIR__) . '/var/messages');
new MessageHandlingExtension()->build($container);
foreach ([GreetingHandler::class, GreetingLog::class] as $service) {
    $container->register($service, $service)->setAutowired(true)->setAutoconfigured(true);
}
$container->compile();
$result = $container->get(MediatorInterface::class)->send(new Greet('Ada'));
if ('Hello, Ada!' !== $result) {
    throw new RuntimeException('Message dispatch failed.');
}
echo $result . "\n";
