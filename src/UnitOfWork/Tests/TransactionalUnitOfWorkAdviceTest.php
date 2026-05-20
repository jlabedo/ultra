<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;
use Ultra\Testing\UltraLightContainerFactoryInterface;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkExtension;

/**
 * @internal
 *
 * @coversNothing
 */
final class TransactionalUnitOfWorkAdviceTest extends TestCase
{
    public function test_write_handler_flushes_inside_transaction(): void
    {
        $log = new TransactionalAdviceLog();

        $container = $this->ultraLight($log)
            ->withClass(TransactionalAdviceWriteHandler::class)
            ->boot()
        ;

        $container->mediator()->send(new TransactionalAdviceWriteCommand());

        self::assertSame([
            'handler:write',
            'transaction:begin',
            'unit_of_work:flush',
            'transaction:commit',
            'unit_of_work:clear',
        ], $log->entries);
    }

    public function test_query_handler_without_writes_does_not_open_transaction(): void
    {
        $log = new TransactionalAdviceLog();

        $container = $this->ultraLight($log)
            ->withClass(TransactionalAdviceQueryHandler::class)
            ->boot()
        ;

        self::assertSame('answer', $container->mediator()->send(new TransactionalAdviceQuery()));
        self::assertSame([
            'handler:query',
            'unit_of_work:clear',
        ], $log->entries);
    }

    public function test_sync_event_handler_without_writes_does_not_open_transaction(): void
    {
        $log = new TransactionalAdviceLog();

        $container = $this->ultraLight($log)
            ->withClass(TransactionalAdviceSyncEventHandler::class)
            ->withEventClass(TransactionalAdviceEvent::class)
            ->boot()
        ;

        $container->eventBus()->publish(new TransactionalAdviceEvent());

        self::assertSame([
            'handler:event',
            'unit_of_work:clear',
        ], $log->entries);
    }

    public function test_async_consumer_opens_outer_unit_of_work_and_handler_joins(): void
    {
        $log = new TransactionalAdviceLog();

        $container = $this->ultraLight($log)
            ->withClass(TransactionalAdviceAsyncHandler::class)
            ->boot()
        ;

        $container->mediator()->send(new TransactionalAdviceAsyncCommand());
        $container->unitOfWork()->flush();
        $log->entries = [];

        self::assertSame(1, $container->queueWorker()->drain());
        self::assertSame([
            'handler:async_write',
            'transaction:begin',
            'unit_of_work:flush',
            'transaction:commit',
            'unit_of_work:clear',
        ], $log->entries);
    }

    /**
     * @return UltraLight<TransactionalAdviceTestContainer>
     */
    private function ultraLight(TransactionalAdviceLog $log): UltraLight
    {
        /** @var UltraLightContainerFactoryInterface<TransactionalAdviceTestContainer> $containerFactory */
        $containerFactory = new class implements UltraLightContainerFactoryInterface {
            public function create(Container $container): TransactionalAdviceTestContainer
            {
                return new TransactionalAdviceTestContainer($container);
            }
        };

        return new UltraLight()
            ->withBuildDir(dirname(__DIR__, 3).'/var/transactional_uow_advice_tests')
            ->withContainerFactory($containerFactory)
            ->withService($log)
            ->withService(new TransactionalAdviceTransactionManager($log), TransactionManager::class)
            ->withService(new TransactionalAdviceUnitOfWork($log))
            ->withClass(TransactionalAdviceNoopEventStore::class)
            ->withAlias(EventStore::class, TransactionalAdviceNoopEventStore::class)
            ->withAlias('test.mediator', MediatorInterface::class)
            ->withAlias('test.event_bus', EventBusInterface::class)
            ->withAlias('test.queue_worker', QueueWorker::class)
            ->withAlias('test.unit_of_work', UnitOfWork::class)
            ->withBundle(new UnitOfWorkExtension(), new MessageHandlingExtension())
        ;
    }
}

final readonly class TransactionalAdviceTestContainer extends UltraLightContainer
{
    public function mediator(): MediatorInterface
    {
        return $this->get('test.mediator');
    }

    public function eventBus(): EventBusInterface
    {
        return $this->get('test.event_bus');
    }

    public function queueWorker(): QueueWorker
    {
        return $this->get('test.queue_worker');
    }

    public function unitOfWork(): UnitOfWork
    {
        return $this->get('test.unit_of_work');
    }
}

final class TransactionalAdviceLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class TransactionalAdviceUnitOfWork implements UnitOfWork
{
    private bool $dirty = false;

    public function __construct(private readonly TransactionalAdviceLog $log) {}

    public function write(): void
    {
        $this->dirty = true;
    }

    public function hasPendingChanges(): bool
    {
        return $this->dirty;
    }

    public function flush(): void
    {
        $this->log->entries[] = 'unit_of_work:flush';
        $this->dirty = false;
    }

    public function clear(): void
    {
        $this->log->entries[] = 'unit_of_work:clear';
        $this->dirty = false;
    }
}

final readonly class TransactionalAdviceTransactionManager implements TransactionManager
{
    public function __construct(private TransactionalAdviceLog $log) {}

    public function transactional(callable $callback): mixed
    {
        $this->log->entries[] = 'transaction:begin';

        try {
            $result = $callback();
            $this->log->entries[] = 'transaction:commit';

            return $result;
        } catch (\Throwable $e) {
            $this->log->entries[] = 'transaction:rollback';

            throw $e;
        }
    }
}

final readonly class TransactionalAdviceWriteCommand {}

final readonly class TransactionalAdviceQuery {}

final readonly class TransactionalAdviceAsyncCommand {}

final readonly class TransactionalAdviceEvent {}

final readonly class TransactionalAdviceWriteHandler
{
    public function __construct(
        private TransactionalAdviceLog $log,
        private TransactionalAdviceUnitOfWork $unitOfWork,
    ) {}

    #[Handles]
    public function handle(TransactionalAdviceWriteCommand $command): void
    {
        $this->log->entries[] = 'handler:write';
        $this->unitOfWork->write();
    }
}

final readonly class TransactionalAdviceQueryHandler
{
    public function __construct(private TransactionalAdviceLog $log) {}

    #[Handles]
    public function handle(TransactionalAdviceQuery $query): string
    {
        $this->log->entries[] = 'handler:query';

        return 'answer';
    }
}

final readonly class TransactionalAdviceSyncEventHandler
{
    public function __construct(private TransactionalAdviceLog $log) {}

    #[EventHandler]
    public function handle(TransactionalAdviceEvent $event): void
    {
        $this->log->entries[] = 'handler:event';
    }
}

final readonly class TransactionalAdviceAsyncHandler
{
    public function __construct(
        private TransactionalAdviceLog $log,
        private TransactionalAdviceUnitOfWork $unitOfWork,
    ) {}

    #[Handles]
    #[Asynchronous]
    public function handle(TransactionalAdviceAsyncCommand $command): void
    {
        $this->log->entries[] = 'handler:async_write';
        $this->unitOfWork->write();
    }
}

final class TransactionalAdviceNoopEventStore implements EventStore
{
    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        return new LoadResult([], $to ?? $from ?? Checkpoint::beginning(), $query);
    }

    /**
     * @param list<Event> $events
     */
    public function append(array $events, AppendCondition ...$appendConditions): void {}
}
