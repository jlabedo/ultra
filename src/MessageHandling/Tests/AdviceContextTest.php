<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
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
use Ultra\MessageHandling\Tests\Support\AdviceOrderingLog;
use Ultra\MessageHandling\Tests\Support\OrderedHandler;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

#[CoversNamespace('Ultra\\MessageHandling')]
#[CoversNamespace('Ultra\\MessageHandling_Compiler')]
#[CoversNamespace('Ultra\\MessageHandling_PostgresQueue')]
final class AdviceContextTest extends MessageHandlingCompilerTestCase
{
    public function test_it_lets_before_advice_merge_a_patch_into_the_context_seen_by_the_handler(): void
    {
        $seen = [];

        $mediator = $this
            ->ultraLight()
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

                    return 'handled: ' . $context->get('executorId') . ':' . $context->get('traceId');
                }
            })
            ->withService(new class {
                #[Before]
                public function enrich(object $message, MessageContext $context): array
                {
                    return ['executorId' => 'user-123'];
                }
            })
            ->boot()
            ->mediator();

        static::assertSame('handled: user-123:trace-1', $mediator->send(new GreetingMessage('payload'), [
            'traceId' => 'trace-1',
        ]));
        static::assertSame('user-123', $seen['executorId']);
        static::assertSame('trace-1', $seen['traceId']);
    }

    public function test_it_lets_after_returning_and_after_advices_merge_patches_into_the_final_context_seen_by_outer_around_advices(): void
    {
        $finalContext = [];

        $mediator = $this
            ->ultraLight()
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message, MessageContext $context): string
                {
                    return $context->get('before') . ':' . $message->name;
                }
            })
            ->withService(new class {
                #[Before(priority: 20)]
                public function enrich(object $message, MessageContext $context): array
                {
                    return ['before' => 'yes'];
                }
            })
            ->withService(new class {
                #[AfterReturning(static function (HandlerMatchContext $match): MatchResult {
                    return MatchResult::apply(config: ['key' => 'afterReturning']);
                }, priority: 20)]
                public function enrich(
                    object $message,
                    MessageContext $context,
                    mixed $returnValue,
                    array $config,
                ): array {
                    return [$config['key'] => $returnValue];
                }
            })
            ->withService(new class {
                #[After(static function (HandlerMatchContext $match): MatchResult {
                    return MatchResult::apply(config: ['key' => 'after']);
                }, priority: 20)]
                public function enrich(object $message, MessageContext $context, array $config): array
                {
                    return [$config['key'] => 'done'];
                }

                #[After(priority: 20)]
                public function enrichSameLayer(object $message, MessageContext $context): array
                {
                    return ['sameLayer' => $context->get('after')];
                }

                #[After(priority: 10)]
                public function enrichOuterLayer(object $message, MessageContext $context): array
                {
                    return ['outerLayer' => $context->get('sameLayer')];
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

                #[Around(priority: 0)]
                public function inspect(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $result = $proceed();
                    $this->finalContext = $result->context->all();

                    return $result;
                }
            })
            ->boot()
            ->mediator();

        static::assertSame('yes:hello', $mediator->send(new GreetingMessage('hello'), ['traceId' => 'trace-1']));
        static::assertSame('trace-1', $finalContext['traceId']);
        static::assertSame('yes', $finalContext['before']);
        static::assertSame('yes:hello', $finalContext['afterReturning']);
        static::assertSame('done', $finalContext['after']);
        static::assertSame('done', $finalContext['sameLayer']);
        static::assertSame('done', $finalContext['outerLayer']);
    }

    public function test_it_passes_derived_context_from_outer_around_advice_to_inner_advice_without_mutating_the_outer_context(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this
            ->ultraLight()
            ->withService($log)
            ->withService(new OrderedHandler($log))
            ->withService(new class($log) {
                public function __construct(
                    private readonly AdviceOrderingLog $log,
                ) {}

                #[Around(priority: 0)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'outer-before:' . $context->get('trace', 'none');
                    $result = $proceed(context: $context->with('trace', 'propagated'));
                    $this->log->entries[] = 'outer-after:' . $context->get('trace', 'none');

                    return $result;
                }
            })
            ->withService(new class($log) {
                public function __construct(
                    private readonly AdviceOrderingLog $log,
                ) {}

                #[Around(priority: 10)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'inner-before:' . $context->get('trace', 'none');
                    $result = $proceed();
                    $this->log->entries[] = 'inner-after:' . $context->get('trace', 'none');

                    return $result;
                }
            })
            ->boot()
            ->mediator();

        static::assertSame('ok', $mediator->send(new GreetingMessage('ok')));
        static::assertSame(
            [
                'outer-before:none',
                'inner-before:propagated',
                'handler:ok',
                'inner-after:propagated',
                'outer-after:none',
            ],
            $log->entries,
        );
    }

    public function test_it_propagates_failure_context_changes_across_around_boundaries(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this
            ->ultraLight()
            ->withService($log)
            ->withService(new class {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    throw new \RuntimeException('boom');
                }
            })
            ->withService(new class {
                #[Around(priority: 10)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    return $proceed();
                }
            })
            ->withService(new class {
                #[Before(priority: 20)]
                public function before(object $message, MessageContext $context): array
                {
                    return ['before' => 'yes'];
                }

                #[AfterThrowing(priority: 20)]
                public function afterThrowing(object $message, MessageContext $context, \Throwable $throwable): array
                {
                    return ['afterThrowing' => $throwable->getMessage()];
                }

                #[After(priority: 20)]
                public function after(object $message, MessageContext $context): array
                {
                    return ['after' => 'done'];
                }
            })
            ->withService(new class($log) {
                public function __construct(
                    private readonly AdviceOrderingLog $log,
                ) {}

                #[AfterThrowing(priority: 0)]
                public function afterThrowing(object $message, MessageContext $context, \Throwable $throwable): void
                {
                    $this->log->entries[] = sprintf(
                        'outer-afterThrowing:%s:%s:%s',
                        $context->get('before', 'none'),
                        $context->get('afterThrowing', 'none'),
                        $context->get('after', 'none'),
                    );
                }

                #[After(priority: 0)]
                public function after(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = sprintf(
                        'outer-after:%s:%s:%s',
                        $context->get('before', 'none'),
                        $context->get('afterThrowing', 'none'),
                        $context->get('after', 'none'),
                    );
                }
            })
            ->boot()
            ->mediator();

        try {
            $mediator->send(new GreetingMessage('fail'));
            static::fail('Expected handler exception to be rethrown.');
        } catch (\RuntimeException $exception) {
            static::assertSame('boom', $exception->getMessage());
        }

        static::assertSame(
            [
                'outer-afterThrowing:yes:boom:done',
                'outer-after:yes:boom:done',
            ],
            $log->entries,
        );
    }
}
