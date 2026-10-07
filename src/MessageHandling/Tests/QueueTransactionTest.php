<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Container;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\MessageHandling\Async\AsyncQueueUnitOfWork;
use Ultra\MessageHandling\Async\AsyncReceiverUnitOfWork;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\MessageHandling\Tests\Support\QueueTestCase;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainer;
use Ultra\Testing\UltraLightContainerFactoryInterface;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkExtension;

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

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class QueueTransactionTest extends QueueTestCase
{
    public function test_enqueue_is_buffered_until_flush(): void
    {
        $inner = new InMemoryQueue();
        $queue = new AsyncQueueUnitOfWork($inner);

        $messageId = $queue->enqueue($this->envelope('first'));

        static::assertSame(1, $queue->pendingCount());
        static::assertSame(0, $inner->count());
        $queue->flush();

        static::assertSame(1, $inner->count());

        $delivery = $inner->reserve();
        static::assertNotNull($delivery);
        static::assertSame($messageId->value, $delivery->messageId->value);
    }

    public function test_clear_drops_buffered_enqueues(): void
    {
        $inner = new InMemoryQueue();
        $queue = new AsyncQueueUnitOfWork($inner);

        $queue->enqueue($this->envelope('first'));
        $queue->clear();
        $queue->flush();

        static::assertSame(0, $queue->pendingCount());
        static::assertSame(0, $inner->count());
    }

    public function test_acknowledge_is_buffered_until_flush(): void
    {
        $inner = new InMemoryQueue();
        $messageId = $inner->enqueue($this->envelope('first'));
        $receiver = new AsyncReceiverUnitOfWork($inner);

        $delivery = $receiver->reserve();
        static::assertNotNull($delivery);

        $receiver->acknowledge($delivery);

        static::assertTrue($receiver->hasPendingChanges());
        static::assertSame(1, $inner->count());

        $receiver->flush();

        static::assertFalse($receiver->hasPendingChanges());
        static::assertSame(0, $inner->count());
        static::assertSame($messageId->value, $delivery->messageId->value);
    }

    /** @return iterable<string, array{bool}> */
    public static function transactionOutcomes(): iterable
    {
        yield 'commit' => [true];
        yield 'rollback' => [false];
    }

    #[DataProvider('transactionOutcomes')]
    public function test_buffered_enqueues_participate_in_the_callers_transaction(bool $commit): void
    {
        $f = $this->fixture('postgres');
        $other = PostgresConnectionSupport::connection(getenv('ULTRA_TEST_POSTGRES_DSN'));
        try {
            $peer = $f->peer($other);
            $buffer = new AsyncQueueUnitOfWork($f->queue);
            $id = $buffer->enqueue($this->envelope('outbox'));
            $f->connection->beginTransaction();
            $buffer->flush();
            static::assertNull($peer->reserve());
            if ($commit)
                $f->connection->commit();
            else
                $f->connection->rollBack();
            $delivery = $peer->reserve();
            if ($commit) {
                static::assertNotNull($delivery);
                static::assertSame($id->value, $delivery->messageId->value);
            } else {
                static::assertNull($delivery);
            }
        } finally {
            $other->close();
        }
    }

    public function test_async_messages_are_only_visible_after_unit_of_work_flush(): void
    {
        $executed = false;

        $container = $this
            ->ultraLight()
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
            ->boot();

        $container->mediator()->send(new UnitOfWorkAsyncMessage());

        static::assertFalse($executed);
        static::assertSame(0, $container->queueWorker()->drain());

        $container->unitOfWork()->flush();

        static::assertSame(1, $container->queueWorker()->drain());
        static::assertTrue($executed);
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
            ->withBuildDir(dirname(__DIR__, 3) . '/var/message_handling_uow_tests')
            ->withContainerFactory($containerFactory)
            ->withBundle(new UnitOfWorkExtension(), new MessageHandlingExtension())
            ->withAlias('test.mediator', MediatorInterface::class)
            ->withAlias('test.queue_worker', QueueWorker::class)
            ->withAlias('test.unit_of_work', UnitOfWork::class);
    }
}
