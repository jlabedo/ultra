<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\EmptyMessage;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class MediatorAdviceExecutionTest extends MessageHandlingCompilerTestCase
{
    public function test_it_runs_before_advice_before_the_handler(): void
    {
        $handledMessage = new GreetingMessage('payload');
        $logged = [];

        $mediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'handled: '.$message->name;
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

                #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function log(object $message, MessageContext $context): void
                {
                    $this->logged[] = $message;
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('handled: payload', $mediator->send($handledMessage));
        self::assertSame([$handledMessage], $logged);
    }

    public function test_it_wraps_handler_invocation_in_around_advice(): void
    {
        $calls = 0;

        $mediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'wrapped: '.$message->name;
                }
            })
            ->withService(new class($calls) {
                public int $calls;

                public function __construct(int &$calls)
                {
                    $this->calls = &$calls;
                }

                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function execute(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    ++$this->calls;

                    return $proceed();
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('wrapped: value', $mediator->send(new GreetingMessage('value')));
        self::assertSame(1, $calls);
    }

    public function test_it_lets_before_advice_merge_a_patch_into_the_context_seen_by_the_handler(): void
    {
        $seen = [];

        $mediator = $this->ultraLight()
            ->withService(new class($seen) {
                /** @var array<string, mixed> */
                public array $seen;

                /**
                 * @param array<string, mixed> $seen
                 */
                public function __construct(array &$seen)
                {
                    $this->seen = &$seen;
                }

                #[Handles]
                public function handle(GreetingMessage $message, MessageContext $context): string
                {
                    $this->seen = $context->all();

                    return 'handled: '.$context->get('executorId').':'.$context->get('traceId');
                }
            })
            ->withService(new class {
                #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function enrich(object $message, MessageContext $context): array
                {
                    return ['executorId' => 'user-123'];
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('handled: user-123:trace-1', $mediator->send(new GreetingMessage('payload'), ['traceId' => 'trace-1']));
        self::assertSame('user-123', $seen['executorId']);
        self::assertSame('trace-1', $seen['traceId']);
    }

    public function test_it_exposes_handler_return_values_to_after_returning_advice(): void
    {
        $captured = null;

        $mediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'observed: '.$message->name;
                }
            })
            ->withService(new class($captured) {
                public mixed $captured;

                public function __construct(mixed &$captured)
                {
                    $this->captured = &$captured;
                }

                #[AfterReturning(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function inspect(object $message, MessageContext $context, mixed $returnValue): void
                {
                    $this->captured = $returnValue;
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('observed: hello', $mediator->send(new GreetingMessage('hello')));
        self::assertSame('observed: hello', $captured);
    }

    public function test_it_runs_after_throwing_advice_and_rethrows_the_handler_exception(): void
    {
        $caught = null;

        $mediator = $this->ultraLight()
            ->withService(new class {
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

                #[AfterThrowing(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function record(object $message, MessageContext $context, \Throwable $throwable): void
                {
                    $this->caught = $throwable;
                }
            })
            ->boot()->mediator()
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

            #[After(static function (HandlerMatchContext $ctx): ?MatchResult {
                return MatchResult::apply();
            })]
            public function cleanup(object $message, MessageContext $context): void
            {
                ++$this->calls;
            }
        };

        $successfulMediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return 'observed: '.$message->name;
                }
            })
            ->withService($cleaner)
            ->boot()->mediator()
        ;

        self::assertSame('observed: world', $successfulMediator->send(new GreetingMessage('world')));

        $failingMediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(EmptyMessage $message): string
                {
                    throw new \RuntimeException('boom');
                }
            })
            ->withService($cleaner)
            ->boot()->mediator()
        ;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $failingMediator->send(new EmptyMessage());
        } finally {
            self::assertSame(2, $calls);
        }
    }

    public function test_it_lets_after_returning_and_after_advices_merge_patches_into_the_final_context_seen_by_outer_around_advices(): void
    {
        $finalContext = [];

        $mediator = $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message, MessageContext $context): string
                {
                    return $context->get('before').':'.$message->name;
                }
            })
            ->withService(new class {
                #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                }, priority: 20)]
                public function enrich(object $message, MessageContext $context): array
                {
                    return ['before' => 'yes'];
                }
            })
            ->withService(new class {
                #[AfterReturning(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                }, priority: 20)]
                public function enrich(object $message, MessageContext $context, mixed $returnValue): array
                {
                    return ['afterReturning' => $returnValue];
                }
            })
            ->withService(new class {
                #[After(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                }, priority: 20)]
                public function enrich(object $message, MessageContext $context): array
                {
                    return ['after' => 'done'];
                }
            })
            ->withService(new class($finalContext) {
                /** @var array<string, mixed> */
                public array $finalContext;

                /**
                 * @param array<string, mixed> $finalContext
                 */
                public function __construct(array &$finalContext)
                {
                    $this->finalContext = &$finalContext;
                }

                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                }, priority: 0)]
                public function inspect(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $result = $proceed();
                    $this->finalContext = $result->context->all();

                    return $result;
                }
            })
            ->boot()->mediator()
        ;

        self::assertSame('yes:hello', $mediator->send(new GreetingMessage('hello'), ['traceId' => 'trace-1']));
        self::assertSame('trace-1', $finalContext['traceId']);
        self::assertSame('yes', $finalContext['before']);
        self::assertSame('yes:hello', $finalContext['afterReturning']);
        self::assertSame('done', $finalContext['after']);
    }

    public function test_it_requires_around_advices_to_return_proceed_result(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Around advice "class@anonymous.*::execute" must declare return type "'.preg_quote(ProceedResult::class, '/').'"\./');

        $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return $message->name;
                }
            })
            ->withService(new class {
                #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function execute(callable $proceed, object $message, MessageContext $context): mixed
                {
                    return $proceed()->value;
                }
            })
            ->boot()
        ;
    }

    public function test_it_requires_non_around_context_mutating_advices_to_return_patch_arrays(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Before advice "class@anonymous.*::log" must declare no return type, "void", or return type "array" \(optionally nullable\)\./');

        $this->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    return $message->name;
                }
            })
            ->withService(new class {
                #[Before(static function (HandlerMatchContext $ctx): ?MatchResult {
                    return MatchResult::apply();
                })]
                public function log(object $message, MessageContext $context): string
                {
                    return 'invalid';
                }
            })
            ->boot()
        ;
    }
}
