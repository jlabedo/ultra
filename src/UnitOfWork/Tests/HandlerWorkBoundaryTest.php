<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\TransactionalUnitOfWorkRunner;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\Tests\Support\CallLog;
use Ultra\UnitOfWork\Tests\Support\ContainerTestCase;
use Ultra\UnitOfWork\Tests\Support\RecordingTransactionManager;
use Ultra\UnitOfWork\Tests\Support\RecordingUnitOfWork;

final class HandlerWorkBoundaryTest extends ContainerTestCase
{
    #[DataProvider('handlerWork')]
    public function test_compiled_handlers_and_hooks_share_one_work_boundary(string $route, bool $writes, bool $hookWrites, ?string $failureStage): void
    {
        $log = new CallLog();
        $unitOfWork = new RecordingUnitOfWork($log);
        $failure = new \RuntimeException('failed '.$failureStage);
        $unitOfWork->flushFailure = 'flush' === $failureStage ? $failure : null;
        $container = $this->ultraLight()
            ->withService($log)->withService($unitOfWork)
            ->withService(new RecordingTransactionManager($log), TransactionManager::class)
            ->withClass(WorkHandlers::class, WorkHooks::class)
            ->withEventClass(BoundaryEvent::class)
            ->withBundle(new MessageHandlingExtension())
            ->withAlias('test.mediator', MediatorInterface::class)
            ->withAlias('test.events', EventBusInterface::class)
            ->withAlias('test.worker', QueueWorker::class)
            ->withAlias('test.hooks', WorkHooks::class)
            ->boot();
        /** @var WorkHooks $hooks */
        $hooks = $container->get('test.hooks');
        $hooks->writes = $hookWrites;
        /** @var MediatorInterface $mediator */
        $mediator = $container->get('test.mediator');
        $context = MessageContext::fromArray(['trace' => 'boundary']);
        $result = null;
        $caught = null;
        try {
            if ('event' === $route) {
                /** @var EventBusInterface $events */
                $events = $container->get('test.events');
                $events->publish(new BoundaryEvent(), $context);
            } elseif ('async_write' === $route) {
                $mediator->send(new AsyncCommand(), $context);
                /** @var UnitOfWork $composite */
                $composite = $container->get('test.unit_of_work');
                $composite->flush();
                $log->entries = [];
                /** @var QueueWorker $worker */
                $worker = $container->get('test.worker');
                self::assertSame(1, $worker->drain());
            } else {
                $result = $mediator->send('query' === $route ? new ReadQuery() : new WriteCommand('work' === $failureStage ? $failure : null), $context);
            }
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        $expected = ['before:active:boundary', 'handler:'.$route.':boundary'];
        if ('work' !== $failureStage) {
            $expected[] = 'after:active:boundary';
            if ($writes || $hookWrites) {
                $expected = [...$expected, 'transaction:begin', 'unit_of_work:flush', 'transaction:'.(null === $failureStage ? 'commit' : 'rollback')];
            }
        }
        self::assertSame([...$expected, 'unit_of_work:clear'], $log->entries);
        self::assertSame(null === $failureStage ? null : $failure, $caught);
        self::assertSame('query' === $route ? 'answer' : null, $result);
        self::assertFalse($unitOfWork->hasPendingChanges());
    }

    /** @return iterable<string, array{string, bool, bool, ?string}> */
    public static function handlerWork(): iterable
    {
        yield 'command write' => ['write', true, false, null];
        yield 'read only query' => ['query', false, false, null];
        yield 'read only event' => ['event', false, false, null];
        yield 'async consumer joins worker' => ['async_write', true, false, null];
        yield 'hook contributes buffered work' => ['query', false, true, null];
        yield 'handler failure' => ['write', true, false, 'work'];
        yield 'flush failure' => ['write', true, false, 'flush'];
    }
}

final readonly class WorkHandlers
{
    public function __construct(private CallLog $log, private RecordingUnitOfWork $unitOfWork) {}

    #[Handles]
    public function write(WriteCommand $command, MessageContext $context): void
    {
        $this->record('write', $context, writes: true);
        if (null !== $command->failure) {
            throw $command->failure;
        }
    }

    #[Handles]
    public function query(ReadQuery $query, MessageContext $context): string
    {
        $this->record('query', $context);

        return 'answer';
    }

    #[EventHandler]
    public function event(BoundaryEvent $event, MessageContext $context): void
    {
        $this->record('event', $context);
    }

    #[Handles]
    #[Asynchronous]
    public function asyncWrite(AsyncCommand $command, MessageContext $context): void
    {
        $this->record('async_write', $context, writes: true);
    }

    private function record(string $route, MessageContext $context, bool $writes = false): void
    {
        $this->log->entries[] = 'handler:'.$route.':'.$context->get('trace');
        $this->unitOfWork->pending = $writes;
    }
}

final class WorkHooks
{
    public bool $writes = false;

    public function __construct(private CallLog $log, private RecordingUnitOfWork $unitOfWork, private TransactionalUnitOfWorkRunner $runner) {}

    #[Before]
    public function before(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'before:'.($this->runner->hasActiveTransaction() ? 'active' : 'idle').':'.$context->get('trace');
    }

    #[AfterReturning]
    public function after(object $message, MessageContext $context, mixed $returnValue): void
    {
        $this->log->entries[] = 'after:'.($this->runner->hasActiveTransaction() ? 'active' : 'idle').':'.$context->get('trace');
        $this->unitOfWork->pending = $this->unitOfWork->pending || $this->writes;
    }
}

final readonly class WriteCommand
{
    public function __construct(public ?\Throwable $failure = null) {}
}

final readonly class ReadQuery {}

final readonly class AsyncCommand {}

final readonly class BoundaryEvent {}
