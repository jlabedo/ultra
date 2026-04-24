<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\EmptyMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\FarewellMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AsyncMediatorDispatchTest extends MessageHandlingCompilerTestCase
{
    public function test_async_handler_enqueues_message_and_returns_null(): void
    {
        $container = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                #[Asynchronous]
                public function greet(GreetingMessage $message): string
                {
                    return 'Hello, '.$message->name.'!';
                }
            })
            ->boot()
        ;

        $result = $container->mediator()->send(new GreetingMessage('Alice'));

        self::assertNull($result);
        self::assertSame(1, $container->asyncQueue()->count());
    }

    public function test_queue_worker_drains_and_executes_handler(): void
    {
        $executed = false;

        $container = $this->ultraLight()
            ->withService(new class($executed) {
                public bool $executed;

                public function __construct(bool &$executed)
                {
                    $this->executed = &$executed;
                }

                #[Handles]
                #[Asynchronous]
                public function handle(EmptyMessage $message): void
                {
                    $this->executed = true;
                }
            })
            ->boot()
        ;

        $container->mediator()->send(new EmptyMessage());

        self::assertFalse($executed);

        $count = $container->queueWorker()->drain();

        self::assertSame(1, $count);
        self::assertTrue($executed);
    }

    public function test_sync_and_async_handlers_on_different_messages(): void
    {
        $syncExecuted = false;

        $container = $this->ultraLight()
            ->withService(new class($syncExecuted) {
                public bool $syncExecuted;

                public function __construct(bool &$syncExecuted)
                {
                    $this->syncExecuted = &$syncExecuted;
                }

                #[Handles]
                public function greet(GreetingMessage $message): string
                {
                    $this->syncExecuted = true;

                    return 'Hello, '.$message->name.'!';
                }

                #[Handles]
                #[Asynchronous]
                public function farewell(FarewellMessage $message): string
                {
                    return 'Goodbye, '.$message->name.'!';
                }
            })
            ->boot()
        ;

        // Sync handler returns result immediately
        $syncResult = $container->mediator()->send(new GreetingMessage('Alice'));
        self::assertSame('Hello, Alice!', $syncResult);
        self::assertTrue($syncExecuted);
        self::assertSame(0, $container->asyncQueue()->count());

        // Async handler returns null and enqueues
        $asyncResult = $container->mediator()->send(new FarewellMessage('Bob'));
        self::assertNull($asyncResult);
        self::assertSame(1, $container->asyncQueue()->count());
    }
}
