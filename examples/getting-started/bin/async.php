<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\Connection;
use GettingStarted\Database;
use GettingStarted\Domain\Contact;
use GettingStarted\Domain\Notification;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\MappedTable\PostgresMappedRowSetAdapterFactory;
use Ultra\Mapper\Attribute\AsValue;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\MessageHandling\Async\AsyncMessageReceiver;
use Ultra\MessageHandling\Async\AsyncMessageSender;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\MessageHandling_PostgresQueue\MapperAsyncEnvelopeCodec;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueue;
use Ultra\StateStore\StateStore;
use Ultra\StateStore_Bundle\StateStoreExtension;
use Ultra\UnitOfWork\DoctrineTransactionManager;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWorkExtension;
use Ultra\UnitOfWork\UnitOfWorkRunner;

#[AsValue]
final readonly class Notify
{
    public function __construct(public string $id, public string $body) {}
}

final readonly class NotificationHandler
{
    public function __construct(private StateStore $states) {}

    #[Handles]
    #[Asynchronous]
    public function notify(Notify $message): void
    {
        if (null === $this->states->observe(Notification::class, $message->id)) {
            $this->states->save(new Notification($message->id, $message->body));
        }
    }
}

$mode = $argv[1] ?? '';
if (!in_array($mode, ['send', 'consume'], true)) {
    throw new InvalidArgumentException('Run with send or consume.');
}
$connection = Database::connect();
$container = new ContainerBuilder();
$container->setParameter('kernel.build_dir', dirname(__DIR__) . '/var/async');
$container->register(Connection::class)->setSynthetic(true)->setPublic(true);
$container->register(TransactionManager::class, DoctrineTransactionManager::class)
    ->setArguments([new Reference(Connection::class)]);
$container->register(MappedRowSetAdapterFactory::class, PostgresMappedRowSetAdapterFactory::class)
    ->setArguments([new Reference(Connection::class)]);
foreach ([new MapperExtension('GettingStartedAsyncGenerated'), new StateStoreExtension(), new UnitOfWorkExtension(), new MessageHandlingExtension()] as $extension) {
    $extension->build($container);
}
foreach ([Contact::class, Notification::class, Notify::class] as $resource) {
    $container->register($resource, $resource)->setAutowired(false)->setAutoconfigured(true);
}
$container->register(NotificationHandler::class, NotificationHandler::class)->setAutowired(true)->setAutoconfigured(true);
$container->register(MapperAsyncEnvelopeCodec::class)->setArguments([new Reference(UltraNormalizer::class)]);
$container->register(PostgresAsyncQueue::class)->setArguments([
    new Reference(Connection::class), new Reference(MapperAsyncEnvelopeCodec::class), 'tutorial', 'tutorial_queue_',
]);
$container->setAlias(AsyncMessageSender::class, PostgresAsyncQueue::class);
$container->setAlias(AsyncMessageReceiver::class, PostgresAsyncQueue::class);
$container->setAlias('app.states', StateStore::class)->setPublic(true);
$container->setAlias('app.runner', UnitOfWorkRunner::class)->setPublic(true);
$container->setAlias('app.worker', QueueWorker::class)->setPublic(true);
$container->compile();
$container->set(Connection::class, $connection);

if ('send' === $mode) {
    $id = 'notification-' . bin2hex(random_bytes(4));
    $states = $container->get('app.states');
    $mediator = $container->get(MediatorInterface::class);
    $container->get('app.runner')->run(static function () use ($states, $mediator, $id): void {
        $states->observe(Contact::class, $id);
        $states->save(new Contact($id, 'Ada'));
        $mediator->send(new Notify($id, 'Welcome, Ada!'));
    });
    echo "Contact and queued notification committed.\n";
} else {
    $processed = $container->get('app.worker')->drain();
    echo 'Processed ' . $processed . " notification(s).\n";
}
