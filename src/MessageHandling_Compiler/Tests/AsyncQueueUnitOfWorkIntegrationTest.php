<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Symfony\Component\DependencyInjection\Container;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingBundle;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;
use Ultra\Testing\UltraLightContainerFactoryInterface;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork_Bundle\UnitOfWorkBundle;

/**
 * @internal
 *
 * @coversNothing
 */
final class AsyncQueueUnitOfWorkIntegrationTest extends \PHPUnit\Framework\TestCase
{
    public function test_async_messages_are_only_visible_after_unit_of_work_flush(): void
    {
        $executed = false;

        $container = $this->ultraLight()
            ->withClass(NoopEventStore::class)
            ->withAlias(EventStore::class, NoopEventStore::class)
            ->withService(new class($executed) {
                public bool $executed;

                public function __construct(bool &$executed)
                {
                    $this->executed = &$executed;
                }

                #[Handles]
                #[Asynchronous]
                public function handle(UnitOfWorkAsyncMessage $message): void
                {
                    $this->executed = true;
                }
            })
            ->boot()
        ;

        $container->mediator()->send(new UnitOfWorkAsyncMessage());

        self::assertFalse($executed);
        self::assertSame(0, $container->queueWorker()->drain());

        $container->unitOfWork()->flush();

        self::assertSame(1, $container->queueWorker()->drain());
        self::assertTrue($executed);
    }

    /**
     * @return UltraLight<MessageHandlingUnitOfWorkTestContainer>
     */
    private function ultraLight(): UltraLight
    {
        /** @var UltraLightContainerFactoryInterface<MessageHandlingUnitOfWorkTestContainer> $containerFactory */
        $containerFactory = new class implements UltraLightContainerFactoryInterface {
            public function create(Container $container): MessageHandlingUnitOfWorkTestContainer
            {
                return new MessageHandlingUnitOfWorkTestContainer($container);
            }
        };

        return new UltraLight()
            ->withBuildDir(dirname(__DIR__, 3).'/var/message_handling_uow_tests')
            ->withContainerFactory($containerFactory)
            ->withBundle(new UnitOfWorkBundle(), new MessageHandlingBundle())
            ->withAlias('test.mediator', MediatorInterface::class)
            ->withAlias('test.queue_worker', QueueWorker::class)
            ->withAlias('test.unit_of_work', UnitOfWork::class)
        ;
    }
}

final readonly class MessageHandlingUnitOfWorkTestContainer extends UltraLightContainer
{
    public function mediator(): MediatorInterface
    {
        return $this->get('test.mediator');
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

final class NoopEventStore implements EventStore
{
    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        return new LoadResult([], $to ?? $from ?? Checkpoint::beginning(), $query);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void {}
}

final readonly class UnitOfWorkAsyncMessage {}
