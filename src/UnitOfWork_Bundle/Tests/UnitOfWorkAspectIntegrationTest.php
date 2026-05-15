<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork_Bundle\Tests;

use Symfony\Component\DependencyInjection\Container;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageHandlingBundle;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;
use Ultra\Testing\UltraLightContainerFactoryInterface;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork_Bundle\UnitOfWorkBundle;

/**
 * @internal
 *
 * @coversNothing
 */
final class UnitOfWorkAspectIntegrationTest extends \PHPUnit\Framework\TestCase
{
    public function test_mediator_handlers_create_a_root_unit_of_work_shared_with_nested_event_handlers(): void
    {
        $recorder = new RecordingMessageUnitOfWork();
        $handlerSawUnitOfWork = false;
        $eventHandlerSawUnitOfWork = false;

        $container = $this->ultraLight($recorder)
            ->withEventClass(UnitOfWorkAspectEvent::class)
            ->withService(new class($handlerSawUnitOfWork) {
                public bool $sawUnitOfWork;

                public function __construct(bool &$sawUnitOfWork)
                {
                    $this->sawUnitOfWork = &$sawUnitOfWork;
                }

                #[Handles]
                public function handle(UnitOfWorkAspectCommand $message, MessageContext $context, EventBusInterface $eventBus): void
                {
                    $this->sawUnitOfWork = $context->has(UnitOfWork::class);

                    $eventBus->publish(new UnitOfWorkAspectEvent(), $context);
                }
            })
            ->withService(new class($eventHandlerSawUnitOfWork) {
                public bool $sawUnitOfWork;

                public function __construct(bool &$sawUnitOfWork)
                {
                    $this->sawUnitOfWork = &$sawUnitOfWork;
                }

                #[EventHandler]
                public function on(UnitOfWorkAspectEvent $event, MessageContext $context): void
                {
                    $this->sawUnitOfWork = $context->has(UnitOfWork::class);
                }
            })
            ->boot()
        ;

        $container->mediator()->send(new UnitOfWorkAspectCommand());

        self::assertTrue($handlerSawUnitOfWork);
        self::assertTrue($eventHandlerSawUnitOfWork);
        self::assertSame(['flush', 'clear'], $recorder->calls);
    }

    public function test_root_unit_of_work_flushes_inside_the_transaction_boundary(): void
    {
        $log = new RecordingTransactionLog();
        $unitOfWork = new RecordingMessageUnitOfWork($log);
        $transactionManager = new RecordingTransactionManager($log);

        $container = $this->ultraLight($unitOfWork, $transactionManager)
            ->withService(new class {
                #[Handles]
                public function handle(UnitOfWorkAspectCommand $message): void {}
            })
            ->boot()
        ;

        $container->mediator()->send(new UnitOfWorkAspectCommand());

        self::assertSame([
            'transaction:begin',
            'unit_of_work:flush',
            'transaction:commit',
            'unit_of_work:clear',
        ], $log->entries);
    }

    public function test_direct_event_bus_publish_does_not_create_a_root_unit_of_work(): void
    {
        $recorder = new RecordingMessageUnitOfWork();
        $eventHandlerSawUnitOfWork = false;

        $container = $this->ultraLight($recorder)
            ->withEventClass(UnitOfWorkAspectEvent::class)
            ->withService(new class($eventHandlerSawUnitOfWork) {
                public bool $sawUnitOfWork;

                public function __construct(bool &$sawUnitOfWork)
                {
                    $this->sawUnitOfWork = &$sawUnitOfWork;
                }

                #[EventHandler]
                public function on(UnitOfWorkAspectEvent $event, MessageContext $context): void
                {
                    $this->sawUnitOfWork = $context->has(UnitOfWork::class);
                }
            })
            ->boot()
        ;

        $container->eventBus()->publish(new UnitOfWorkAspectEvent());

        self::assertFalse($eventHandlerSawUnitOfWork);
        self::assertSame([], $recorder->calls);
    }

    public function test_root_unit_of_work_is_cleared_when_the_handler_fails(): void
    {
        $recorder = new RecordingMessageUnitOfWork();

        $container = $this->ultraLight($recorder)
            ->withService(new class {
                #[Handles]
                public function handle(UnitOfWorkAspectCommand $message): void
                {
                    throw new \RuntimeException('boom');
                }
            })
            ->boot()
        ;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $container->mediator()->send(new UnitOfWorkAspectCommand());
        } finally {
            self::assertSame(['clear'], $recorder->calls);
        }
    }

    /**
     * @return UltraLight<UnitOfWorkAspectTestContainer>
     */
    private function ultraLight(
        RecordingMessageUnitOfWork $unitOfWork,
        ?TransactionManager $transactionManager = null,
    ): UltraLight
    {
        /** @var UltraLightContainerFactoryInterface<UnitOfWorkAspectTestContainer> $containerFactory */
        $containerFactory = new class implements UltraLightContainerFactoryInterface {
            public function create(Container $container): UnitOfWorkAspectTestContainer
            {
                return new UnitOfWorkAspectTestContainer($container);
            }
        };

        $ultraLight = new UltraLight()
            ->withBuildDir(dirname(__DIR__, 3).'/var/unit_of_work_message_handling_tests')
            ->withContainerFactory($containerFactory)
            ->withBundle(new UnitOfWorkBundle(), new MessageHandlingBundle())
            ->withClass(UnitOfWorkAspectNoopEventStore::class)
            ->withAlias(EventStore::class, UnitOfWorkAspectNoopEventStore::class)
            ->withService($unitOfWork)
            ->withAlias('test.event_bus', EventBusInterface::class)
            ->withAlias('test.mediator', MediatorInterface::class)
        ;

        if (null !== $transactionManager) {
            $ultraLight = $ultraLight->withService($transactionManager, TransactionManager::class);
        }

        return $ultraLight;
    }
}

final class RecordingMessageUnitOfWork implements UnitOfWork
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private ?RecordingTransactionLog $log = null) {}

    public function flush(): void
    {
        $this->calls[] = 'flush';
        if (null !== $this->log) {
            $this->log->entries[] = 'unit_of_work:flush';
        }
    }

    public function clear(): void
    {
        $this->calls[] = 'clear';
        if (null !== $this->log) {
            $this->log->entries[] = 'unit_of_work:clear';
        }
    }
}

final class RecordingTransactionLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class RecordingTransactionManager implements TransactionManager
{
    public function __construct(private RecordingTransactionLog $log) {}

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

final readonly class UnitOfWorkAspectTestContainer extends UltraLightContainer
{
    public function mediator(): MediatorInterface
    {
        return $this->get('test.mediator');
    }

    public function eventBus(): EventBusInterface
    {
        return $this->get('test.event_bus');
    }
}

final class UnitOfWorkAspectNoopEventStore implements EventStore
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

final readonly class UnitOfWorkAspectCommand {}

final readonly class UnitOfWorkAspectEvent {}
