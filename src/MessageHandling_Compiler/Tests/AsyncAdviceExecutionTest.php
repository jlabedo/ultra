<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

final class AsyncAdviceExecutionTest extends MessageHandlingCompilerTestCase
{
    public function test_advices_run_at_dequeue_time_not_at_enqueue_time(): void
    {
        $beforeCalls = 0;
        $aroundCalls = 0;

        $container = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                #[Asynchronous]
                public function handle(GreetingMessage $message): string
                {
                    return 'handled: ' . $message->name;
                }
            })
            ->withService(new class($beforeCalls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Before(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function log(object $message, MessageContext $context): void
                {
                    ++$this->calls;
                }
            })
            ->withService(new class($aroundCalls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Around(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function wrap(callable $proceed, object $message, MessageContext $context): mixed
                {
                    ++$this->calls;

                    return $proceed();
                }
            })
            ->boot()
        ;

        /** @var MediatorInterface $mediator */
        $mediator = $container->get('test.mediator');
        /** @var QueueWorker $worker */
        $worker = $container->get('test.queue_worker');

        // Send — message is enqueued, advices NOT called
        $mediator->send(new GreetingMessage('test'));
        self::assertSame(0, $beforeCalls);
        self::assertSame(0, $aroundCalls);

        // Drain — handler executes with full advice chain
        $worker->drain();
        self::assertSame(1, $beforeCalls);
        self::assertSame(1, $aroundCalls);
    }
}
