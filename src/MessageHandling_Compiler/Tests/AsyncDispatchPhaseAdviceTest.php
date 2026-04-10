<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\AdvicePhase;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AsyncDispatchPhaseAdviceTest extends MessageHandlingCompilerTestCase
{
    public function test_dispatch_phase_advice_runs_at_enqueue_time(): void
    {
        $dispatchCalls = 0;

        $container = $this->ultraLight()
            ->withService(new class () {
                #[Handles]
                #[Asynchronous]
                public function handle(GreetingMessage $message): string
                {
                    return 'handled: '.$message->name;
                }
            })
            ->withService(new class ($dispatchCalls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply(phases: [AdvicePhase::Dispatch]);
                })]
                public function validate(object $message, MessageContext $context): void
                {
                    ++$this->calls;
                }
            })
            ->boot()
        ;


        // Dispatch-phase advice runs at send time (enqueue)
        $container->mediator()->send(new GreetingMessage('test'));
        self::assertSame(1, $dispatchCalls);

        // Draining does NOT run the dispatch advice again
        $container->queueWorker()->drain();
        self::assertSame(1, $dispatchCalls);
    }

    public function test_dispatch_phase_advice_also_runs_for_sync_handlers(): void
    {
        $dispatchCalls = 0;

        $container = $this->ultraLight()
            ->withService(new class () {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'handled: '.$message->name;
                }
            })
            ->withService(new class ($dispatchCalls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply(phases: [AdvicePhase::Dispatch]);
                })]
                public function validate(object $message, MessageContext $context): void
                {
                    ++$this->calls;
                }
            })
            ->boot()
        ;

        $result = $container->mediator()->send(new GreetingMessage('Alice'));
        self::assertSame('handled: Alice', $result);
        self::assertSame(1, $dispatchCalls);
    }

    public function test_execute_phase_advice_does_not_run_at_dispatch_time(): void
    {
        $executeCalls = 0;

        $container = $this->ultraLight()
            ->withService(new class () {
                #[Handles]
                #[Asynchronous]
                public function handle(GreetingMessage $message): string
                {
                    return 'handled: '.$message->name;
                }
            })
            ->withService(new class ($executeCalls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply(phases: [AdvicePhase::Execute]);
                })]
                public function audit(object $message, MessageContext $context): void
                {
                    ++$this->calls;
                }
            })
            ->boot()
        ;


        // Send — execute-phase advice does NOT run at dispatch time
        $container->mediator()->send(new GreetingMessage('test'));
        self::assertSame(0, $executeCalls);

        // Drain — execute-phase advice runs at dequeue
        $container->queueWorker()->drain();
        self::assertSame(1, $executeCalls);
    }

    public function test_mixed_dispatch_and_execute_advices(): void
    {
        $dispatchCalls = 0;
        $executeCalls = 0;

        $container = $this->ultraLight()
            ->withService(new class () {
                #[Handles]
                #[Asynchronous]
                public function handle(GreetingMessage $message): string
                {
                    return 'handled: '.$message->name;
                }
            })
            ->withService(new class ($dispatchCalls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply(phases: [AdvicePhase::Dispatch]);
                })]
                public function validate(object $message, MessageContext $context): void
                {
                    ++$this->calls;
                }
            })
            ->withService(new class ($executeCalls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply(phases: [AdvicePhase::Execute]);
                })]
                public function audit(object $message, MessageContext $context): void
                {
                    ++$this->calls;
                }
            })
            ->boot()
        ;


        // Send: dispatch runs, execute does not
        $container->mediator()->send(new GreetingMessage('test'));
        self::assertSame(1, $dispatchCalls);
        self::assertSame(0, $executeCalls);

        // Drain: execute runs, dispatch does not repeat
        $container->queueWorker()->drain();
        self::assertSame(1, $dispatchCalls);
        self::assertSame(1, $executeCalls);
    }
}
