<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\EmptyMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

final class MediatorAdviceExecutionTest extends MessageHandlingCompilerTestCase
{
    public function test_it_runs_before_advice_before_the_handler(): void
    {
        $handledMessage = new GreetingMessage('payload');
        $logged = [];

        $mediator = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'handled: ' . $message->name;
                }
            })
            ->withService(new class($logged) {
                /** @var list<object> */
                public array $logged;

                /**
                 * @param list<object> $logged
                 */
                public function __construct(array &$logged)
                {
                    $this->logged = &$logged;
                }

                #[Before(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function log(object $message, MessageContext $context): void
                {
                    $this->logged[] = $message;
                }
            })
            ->mediator()
        ;

        self::assertSame('handled: payload', $mediator->send($handledMessage));
        self::assertSame([$handledMessage], $logged);
    }

    public function test_it_wraps_handler_invocation_in_around_advice(): void
    {
        $calls = 0;

        $mediator = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'wrapped: ' . $message->name;
                }
            })
            ->withService(new class($calls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Around(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function execute(callable $proceed, object $message, MessageContext $context): mixed
                {
                    ++$this->calls;

                    return $proceed();
                }
            })
            ->mediator()
        ;

        self::assertSame('wrapped: value', $mediator->send(new GreetingMessage('value')));
        self::assertSame(1, $calls);
    }

    public function test_it_exposes_handler_return_values_to_after_returning_advice(): void
    {
        $captured = null;

        $mediator = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'observed: ' . $message->name;
                }
            })
            ->withService(new class($captured) {
                public mixed $captured;

                public function __construct(mixed &$captured)
                {
                    $this->captured = &$captured;
                }

                #[AfterReturning(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function inspect(object $message, MessageContext $context, mixed $returnValue): void
                {
                    $this->captured = $returnValue;
                }
            })
            ->mediator()
        ;

        self::assertSame('observed: hello', $mediator->send(new GreetingMessage('hello')));
        self::assertSame('observed: hello', $captured);
    }

    public function test_it_runs_after_throwing_advice_and_rethrows_the_handler_exception(): void
    {
        $caught = null;

        $mediator = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                public function handle(EmptyMessage $message): string
                {
                    throw new \RuntimeException('handler failed');
                }
            })
            ->withService(new class($caught) {
                public ?\Throwable $caught;

                public function __construct(?\Throwable &$caught)
                {
                    $this->caught = &$caught;
                }

                #[AfterThrowing(static function (\ReflectionMethod $method): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function record(object $message, MessageContext $context, \Throwable $throwable): void
                {
                    $this->caught = $throwable;
                }
            })
            ->mediator()
        ;

        try {
            $mediator->send(new EmptyMessage());
            self::fail('Expected handler exception to be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('handler failed', $exception->getMessage());
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
    }

    public function test_it_runs_after_advice_after_successful_and_failed_calls(): void
    {
        $calls = 0;

        $cleaner = new class($calls) {
            public int $calls;

            public function __construct(int &$calls)
            {
                $this->calls = &$calls;
            }

            #[After(static function (\ReflectionMethod $method): ?MatchResult {
                return MatchResult::apply();
            })]
            public function cleanup(object $message, MessageContext $context): void
            {
                ++$this->calls;
            }
        };

        $successfulMediator = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'observed: ' . $message->name;
                }
            })
            ->withService($cleaner)
            ->mediator()
        ;

        self::assertSame('observed: world', $successfulMediator->send(new GreetingMessage('world')));

        $failingMediator = $this->ultraLight()
            ->withService(new class() {
                #[Handles]
                public function handle(EmptyMessage $message): string
                {
                    throw new \RuntimeException('boom');
                }
            })
            ->withService($cleaner)
            ->mediator()
        ;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $failingMediator->send(new EmptyMessage());
        } finally {
            self::assertSame(2, $calls);
        }
    }
}
