<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\RecordedEvent;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AsyncEventBusDispatchTest extends MessageHandlingCompilerTestCase
{
    public function test_mixed_sync_and_async_event_handlers(): void
    {
        $syncLog = [];
        $asyncLog = [];

        $container = $this->ultraLight()
            ->withEventClass(RecordedEvent::class)
            ->withService(new class ($syncLog) {
                /** @var list<string> */
                public array $log;

                /** @param list<string> $log */
                public function __construct(array &$log)
                {
                    $this->log = &$log;
                }

                #[EventHandler]
                public function onEvent(RecordedEvent $event): void
                {
                    $this->log[] = 'sync: '.$event->payload;
                }
            })
            ->withService(new class ($asyncLog) {
                /** @var list<string> */
                public array $log;

                /** @param list<string> $log */
                public function __construct(array &$log)
                {
                    $this->log = &$log;
                }

                #[EventHandler]
                #[Asynchronous]
                public function onEvent(RecordedEvent $event): void
                {
                    $this->log[] = 'async: '.$event->payload;
                }
            })
            ->boot()
        ;

        /** @var EventBusInterface $eventBus */
        $eventBus = $container->get('test.event_bus');
        /** @var InMemoryQueue $queue */
        $queue = $container->get('test.async_queue');
        /** @var QueueWorker $worker */
        $worker = $container->get('test.queue_worker');

        $eventBus->publish(new RecordedEvent('data'));

        // Sync handler ran immediately
        self::assertSame(['sync: data'], $syncLog);
        // Async handler was enqueued, not executed yet
        self::assertSame([], $asyncLog);
        self::assertSame(1, $queue->count());

        // Drain the queue
        $worker->drain();

        self::assertSame(['async: data'], $asyncLog);
        self::assertTrue($queue->isEmpty());
    }

    public function test_all_handlers_async_none_run_immediately(): void
    {
        $log = [];

        $container = $this->ultraLight()
            ->withEventClass(RecordedEvent::class)
            ->withService(new class ($log) {
                /** @var list<string> */
                public array $log;

                /** @param list<string> $log */
                public function __construct(array &$log)
                {
                    $this->log = &$log;
                }

                #[EventHandler]
                #[Asynchronous]
                public function handlerA(RecordedEvent $event): void
                {
                    $this->log[] = 'A: '.$event->payload;
                }

                #[EventHandler]
                #[Asynchronous]
                public function handlerB(RecordedEvent $event): void
                {
                    $this->log[] = 'B: '.$event->payload;
                }
            })
            ->boot()
        ;

        /** @var EventBusInterface $eventBus */
        $eventBus = $container->get('test.event_bus');
        /** @var InMemoryQueue $queue */
        $queue = $container->get('test.async_queue');
        /** @var QueueWorker $worker */
        $worker = $container->get('test.queue_worker');

        $eventBus->publish(new RecordedEvent('payload'));

        self::assertSame([], $log);
        self::assertSame(2, $queue->count());

        $count = $worker->drain();

        self::assertSame(2, $count);
        self::assertCount(2, $log);
    }
}
