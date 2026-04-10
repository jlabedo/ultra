<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\EventHandler;
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

        $container->eventBus()->publish(new RecordedEvent('data'));

        // Sync handler ran immediately
        self::assertSame(['sync: data'], $syncLog);
        // Async handler was enqueued, not executed yet
        self::assertSame([], $asyncLog);
        self::assertSame(1, $container->asyncQueue()->count());

        // Drain the queue
        $container->queueWorker()->drain();

        self::assertSame(['async: data'], $asyncLog);
        self::assertTrue($container->asyncQueue()->isEmpty());
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

        $container->eventBus()->publish(new RecordedEvent('payload'));

        self::assertSame([], $log);
        self::assertSame(2, $container->asyncQueue()->count());

        $count = $container->queueWorker()->drain();

        self::assertSame(2, $count);
        self::assertCount(2, $log);
    }
}
