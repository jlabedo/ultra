<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MessageHandling\Attribute\AdvicePhase;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Asynchronous;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
final class AdvicePhasesTest extends MessageHandlingCompilerTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function deliveryModes(): iterable
    {
        yield 'inline' => [false];
        yield 'queued' => [true];
    }

    #[DataProvider('deliveryModes')]
    public function test_dispatch_advice_runs_once_while_execute_and_default_advice_follow_execution(bool $async): void
    {
        $log = new PhaseLog();
        $container = $this->ultraLight()
            ->withService($async ? new QueuedPhaseHandler() : new InlinePhaseHandler())
            ->withService(new class($log) {
                public function __construct(private readonly PhaseLog $log) {}

                #[Before(static function (HandlerMatchContext $ctx): MatchResult {
                    return MatchResult::apply(phases: [AdvicePhase::Dispatch]);
                })]
                public function dispatch(object $message, MessageContext $context): void
                {
                    ++$this->log->dispatch;
                }

                #[Before(static function (HandlerMatchContext $ctx): MatchResult {
                    return MatchResult::apply(phases: [AdvicePhase::Execute]);
                })]
                public function execute(object $message, MessageContext $context): void
                {
                    ++$this->log->execute;
                }

                #[Before]
                public function before(object $message, MessageContext $context): void
                {
                    ++$this->log->before;
                }

                #[Around]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    ++$this->log->around;
                    return $proceed();
                }
            })
            ->boot();

        $result = $container->mediator()->send(new GreetingMessage('test'));
        self::assertSame($async ? null : 'handled: test', $result);
        self::assertSame(1, $log->dispatch);
        self::assertSame($async ? 0 : 1, $log->execute);
        self::assertSame($async ? 0 : 1, $log->before);
        self::assertSame($async ? 0 : 1, $log->around);
        self::assertSame($async ? 1 : 0, $container->queueWorker()->drain());
        self::assertSame(1, $log->dispatch);
        self::assertSame(1, $log->execute);
        self::assertSame(1, $log->before);
        self::assertSame(1, $log->around);
    }
}

final class PhaseLog
{
    public int $dispatch = 0;
    public int $execute = 0;
    public int $before = 0;
    public int $around = 0;
}

final class InlinePhaseHandler
{
    #[Handles]
    public function handle(GreetingMessage $message): string
    {
        return 'handled: '.$message->name;
    }
}

final class QueuedPhaseHandler
{
    #[Handles]
    #[Asynchronous]
    public function handle(GreetingMessage $message): string
    {
        return 'handled: '.$message->name;
    }
}
