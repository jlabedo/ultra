<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests;

use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\MessageHandling_Compiler\Tests\Fixtures\GreetingMessage;
use Ultra\MessageHandling_Compiler\Tests\Support\MessageHandlingCompilerTestCase;

final class AdviceOrderingLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class AdviceOrderingAlphabeticalBeforeA
{
    public function __construct(private readonly AdviceOrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'before:A';
    }
}

final class AdviceOrderingAlphabeticalBeforeB
{
    public function __construct(private readonly AdviceOrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'before:B';
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class MediatorAdviceOrderingTest extends MessageHandlingCompilerTestCase
{
    public function test_it_places_before_around_and_after_advice_in_onion_order(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this->ultraLight()
            ->withService($log)
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    $this->log->entries[] = 'handler:'.$message->name;

                    return $message->name;
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Before(priority: 20)]
                public function before(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = 'before:20';
                }

                #[Around(priority: 10)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'around:10:before';
                    $result = $proceed();
                    $this->log->entries[] = 'around:10:after';

                    return $result;
                }

                #[After(priority: 5)]
                public function after(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = 'after:5';
                }
            })
            ->mediator()
        ;

        self::assertSame('ok', $mediator->send(new GreetingMessage('ok')));
        self::assertSame([
            'around:10:before',
            'before:20',
            'handler:ok',
            'around:10:after',
            'after:5',
        ], $log->entries);
    }

    public function test_it_orders_same_priority_advice_as_before_around_handler_after_returning_after(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this->ultraLight()
            ->withService($log)
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    $this->log->entries[] = 'handler:'.$message->name;

                    return $message->name;
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Before(priority: 10)]
                public function before(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = 'before:10';
                }

                #[Around(priority: 10)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'around:10:before';
                    $result = $proceed();
                    $this->log->entries[] = 'around:10:after';

                    return $result;
                }

                #[AfterReturning(priority: 10)]
                public function afterReturning(object $message, MessageContext $context, mixed $result): void
                {
                    $this->log->entries[] = 'afterReturning:10:'.$result;
                }

                #[After(priority: 10)]
                public function after(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = 'after:10';
                }
            })
            ->mediator()
        ;

        self::assertSame('ok', $mediator->send(new GreetingMessage('ok')));
        self::assertSame([
            'before:10',
            'around:10:before',
            'handler:ok',
            'around:10:after',
            'afterReturning:10:ok',
            'after:10',
        ], $log->entries);
    }

    public function test_it_runs_same_priority_after_throwing_before_after_on_failures(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this->ultraLight()
            ->withService($log)
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    $this->log->entries[] = 'handler:'.$message->name;

                    throw new \RuntimeException('boom');
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Before(priority: 10)]
                public function before(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = 'before:10';
                }

                #[Around(priority: 10)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'around:10:before';

                    return $proceed();
                }

                #[AfterThrowing(priority: 10)]
                public function afterThrowing(object $message, MessageContext $context, \Throwable $throwable): void
                {
                    $this->log->entries[] = 'afterThrowing:10:'.$throwable->getMessage();
                }

                #[After(priority: 10)]
                public function after(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = 'after:10';
                }
            })
            ->mediator()
        ;

        try {
            $mediator->send(new GreetingMessage('fail'));
            self::fail('Expected handler exception to be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame([
            'before:10',
            'around:10:before',
            'handler:fail',
            'afterThrowing:10:boom',
            'after:10',
        ], $log->entries);
    }

    public function test_it_nests_interleaved_priorities_correctly(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this->ultraLight()
            ->withService($log)
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    $this->log->entries[] = 'handler:'.$message->name;

                    return $message->name;
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Around(priority: 0)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'around:0:before';
                    $result = $proceed();
                    $this->log->entries[] = 'around:0:after';

                    return $result;
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Before(priority: 10)]
                public function before(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = 'before:10';
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Around(priority: 20)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'around:20:before';
                    $result = $proceed();
                    $this->log->entries[] = 'around:20:after';

                    return $result;
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[After(priority: -5)]
                public function after(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = 'after:-5';
                }
            })
            ->mediator()
        ;

        self::assertSame('ok', $mediator->send(new GreetingMessage('ok')));
        self::assertSame([
            'around:0:before',
            'before:10',
            'around:20:before',
            'handler:ok',
            'around:20:after',
            'around:0:after',
            'after:-5',
        ], $log->entries);
    }

    public function test_it_breaks_same_type_same_priority_ties_by_class_name(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this->ultraLight()
            ->withService($log)
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    $this->log->entries[] = 'handler:'.$message->name;

                    return $message->name;
                }
            })
            ->withService(new AdviceOrderingAlphabeticalBeforeB($log))
            ->withService(new AdviceOrderingAlphabeticalBeforeA($log))
            ->mediator()
        ;

        self::assertSame('ok', $mediator->send(new GreetingMessage('ok')));
        self::assertSame([
            'before:A',
            'before:B',
            'handler:ok',
        ], $log->entries);
    }

    public function test_it_places_interleaved_after_throwing_inside_outer_after_handling(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this->ultraLight()
            ->withService($log)
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    $this->log->entries[] = 'handler:'.$message->name;

                    throw new \RuntimeException('boom');
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Around(priority: 0)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'around:0:before';

                    return $proceed();
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[AfterThrowing(priority: 10)]
                public function afterThrowing(object $message, MessageContext $context, \Throwable $throwable): void
                {
                    $this->log->entries[] = 'afterThrowing:10:'.$throwable->getMessage();
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[After(priority: -5)]
                public function after(object $message, MessageContext $context): void
                {
                    $this->log->entries[] = 'after:-5';
                }
            })
            ->mediator()
        ;

        try {
            $mediator->send(new GreetingMessage('fail'));
            self::fail('Expected handler exception to be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame([
            'around:0:before',
            'handler:fail',
            'afterThrowing:10:boom',
            'after:-5',
        ], $log->entries);
    }

    public function test_it_passes_derived_context_from_outer_around_advice_to_inner_advice_without_mutating_the_outer_context(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this->ultraLight()
            ->withService($log)
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    $this->log->entries[] = 'handler:'.$message->name;

                    return $message->name;
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Around(priority: 0)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'outer-before:'.$context->get('trace', 'none');
                    $result = $proceed(context: $context->with('trace', 'propagated'));
                    $this->log->entries[] = 'outer-after:'.$context->get('trace', 'none');

                    return $result;
                }
            })
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

                #[Around(priority: 10)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    $this->log->entries[] = 'inner-before:'.$context->get('trace', 'none');
                    $result = $proceed();
                    $this->log->entries[] = 'inner-after:'.$context->get('trace', 'none');

                    return $result;
                }
            })
            ->mediator()
        ;

        self::assertSame('ok', $mediator->send(new GreetingMessage('ok')));
        self::assertSame([
            'outer-before:none',
            'inner-before:propagated',
            'handler:ok',
            'inner-after:propagated',
            'outer-after:none',
        ], $log->entries);
    }

    public function test_it_propagates_failure_context_changes_across_around_boundaries(): void
    {
        $log = new AdviceOrderingLog();

        $mediator = $this->ultraLight()
            ->withService($log)
            ->withService(new class () {
                #[Handles]
                public function handle(GreetingMessage $message): string
                {
                    throw new \RuntimeException('boom');
                }
            })
            ->withService(new class () {
                #[Around(priority: 10)]
                public function around(callable $proceed, object $message, MessageContext $context): ProceedResult
                {
                    return $proceed();
                }
            })
            ->withService(new class () {
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
            ->withService(new class ($log) {
                public function __construct(private readonly AdviceOrderingLog $log) {}

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
            ->mediator()
        ;

        try {
            $mediator->send(new GreetingMessage('fail'));
            self::fail('Expected handler exception to be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame([
            'outer-afterThrowing:yes:boom:done',
            'outer-after:yes:boom:done',
        ], $log->entries);
    }
}
