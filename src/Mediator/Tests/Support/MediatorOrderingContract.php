<?php

declare(strict_types=1);

namespace Ultra\Mediator\Tests\Support;

use Ultra\Mediator\Attribute\Handles;
use Ultra\MessageHandling\Attribute\After;
use Ultra\MessageHandling\Attribute\AfterReturning;
use Ultra\MessageHandling\Attribute\AfterThrowing;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\Before;
use Ultra\MessageHandling\MessageContext;

final readonly class OrderingMessage
{
    public function __construct(public string $payload) {}
}

final class OrderingLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class OrderingHandler
{
    public function __construct(public OrderingLog $log) {}

    #[Handles]
    public function handle(OrderingMessage $message): string
    {
        $this->log->entries[] = 'handler:' . $message->payload;

        return $message->payload;
    }
}

final class OrderingAspect
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 20)]
    public function before(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'before:20';
    }

    #[Around(priority: 10)]
    public function around(callable $proceed, object $message, MessageContext $context): mixed
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
}

final class SamePriorityOrderingAspect
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'before:10';
    }

    #[Around(priority: 10)]
    public function around(callable $proceed, object $message, MessageContext $context): mixed
    {
        $this->log->entries[] = 'around:10:before';
        $result = $proceed();
        $this->log->entries[] = 'around:10:after';

        return $result;
    }

    #[AfterReturning(priority: 10)]
    public function afterReturning(object $message, MessageContext $context, mixed $result): void
    {
        $this->log->entries[] = 'afterReturning:10:' . $result;
    }

    #[AfterThrowing(priority: 10)]
    public function afterThrowing(object $message, MessageContext $context, \Throwable $throwable): void
    {
        $this->log->entries[] = 'afterThrowing:10:' . $throwable->getMessage();
    }

    #[After(priority: 10)]
    public function after(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'after:10';
    }
}

final class OrderingThrowingHandler
{
    public function __construct(public OrderingLog $log) {}

    #[Handles]
    public function handle(OrderingMessage $message): string
    {
        $this->log->entries[] = 'handler:' . $message->payload;

        throw new \RuntimeException('boom');
    }
}

final class InterleavedAroundOuter
{
    public function __construct(public OrderingLog $log) {}

    #[Around(priority: 0)]
    public function around(callable $proceed, object $message, MessageContext $context): mixed
    {
        $this->log->entries[] = 'around:0:before';
        $result = $proceed();
        $this->log->entries[] = 'around:0:after';

        return $result;
    }
}

final class InterleavedBeforeMid
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'before:10';
    }
}

final class InterleavedAroundInner
{
    public function __construct(public OrderingLog $log) {}

    #[Around(priority: 20)]
    public function around(callable $proceed, object $message, MessageContext $context): mixed
    {
        $this->log->entries[] = 'around:20:before';
        $result = $proceed();
        $this->log->entries[] = 'around:20:after';

        return $result;
    }
}

final class AlphabeticalBeforeA
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'before:A';
    }
}

final class AlphabeticalBeforeB
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'before:B';
    }
}

final class InterleavedAfterThrowingMid
{
    public function __construct(public OrderingLog $log) {}

    #[AfterThrowing(priority: 10)]
    public function afterThrowing(object $message, MessageContext $context, \Throwable $throwable): void
    {
        $this->log->entries[] = 'afterThrowing:10:' . $throwable->getMessage();
    }
}

final class InterleavedAfterOuter
{
    public function __construct(public OrderingLog $log) {}

    #[After(priority: -5)]
    public function after(object $message, MessageContext $context): void
    {
        $this->log->entries[] = 'after:-5';
    }
}

final class ContextPropagatingOuterAspect
{
    public function __construct(public OrderingLog $log) {}

    #[Around(priority: 0)]
    public function around(callable $proceed, object $message, MessageContext $context): mixed
    {
        $this->log->entries[] = 'context:outer-before:' . $context->get('trace', 'none');
        $result = $proceed(context: $context->with('trace', 'propagated'));
        $this->log->entries[] = 'context:outer-after:' . $context->get('trace', 'none');

        return $result;
    }
}

final class ContextObservingInnerAspect
{
    public function __construct(public OrderingLog $log) {}

    #[Around(priority: 10)]
    public function around(callable $proceed, object $message, MessageContext $context): mixed
    {
        $this->log->entries[] = 'context:inner-before:' . $context->get('trace', 'none');
        $result = $proceed();
        $this->log->entries[] = 'context:inner-after:' . $context->get('trace', 'none');

        return $result;
    }
}

function registerMediatorOrderingContractTests(MediatorFactoryInterface $factory): void
{
    it('places before, around, and after advice in the expected onion layers', function () use ($factory) {
        $log = new OrderingLog();
        $mediator = $factory->createMediator([
            new OrderingHandler($log),
            new OrderingAspect($log),
        ], [], 'OrderingLayers');

        $mediator->send(new OrderingMessage('ok'));

        expect($log->entries)->toBe([
            'around:10:before',
            'before:20',
            'handler:ok',
            'around:10:after',
            'after:5',
        ]);
    });

    it('orders same-priority advice as before, around, handler, afterReturning, after', function () use ($factory) {
        $log = new OrderingLog();
        $mediator = $factory->createMediator([
            new OrderingHandler($log),
            new SamePriorityOrderingAspect($log),
        ], [], 'OrderingSamePrioritySuccess');

        $mediator->send(new OrderingMessage('ok'));

        expect($log->entries)->toBe([
            'before:10',
            'around:10:before',
            'handler:ok',
            'around:10:after',
            'afterReturning:10:ok',
            'after:10',
        ]);
    });

    it('runs same-priority afterThrowing before after on failures', function () use ($factory) {
        $log = new OrderingLog();
        $mediator = $factory->createMediator([
            new OrderingThrowingHandler($log),
            new SamePriorityOrderingAspect($log),
        ], [], 'OrderingSamePriorityFailure');

        expect(fn() => $mediator->send(new OrderingMessage('fail')))->toThrow(\RuntimeException::class, 'boom');

        expect($log->entries)->toBe([
            'before:10',
            'around:10:before',
            'handler:fail',
            'afterThrowing:10:boom',
            'after:10',
        ]);
    });

    it('nests interleaved priorities correctly across advice layers', function () use ($factory) {
        $log = new OrderingLog();
        $mediator = $factory->createMediator([
            new OrderingHandler($log),
            new InterleavedAroundOuter($log),
            new InterleavedBeforeMid($log),
            new InterleavedAroundInner($log),
            new InterleavedAfterOuter($log),
        ], [], 'OrderingInterleavedLayers');

        $mediator->send(new OrderingMessage('ok'));

        expect($log->entries)->toBe([
            'around:0:before',
            'before:10',
            'around:20:before',
            'handler:ok',
            'around:20:after',
            'around:0:after',
            'after:-5',
        ]);
    });

    it('orders same-type same-priority advice by class name', function () use ($factory) {
        $log = new OrderingLog();
        $mediator = $factory->createMediator([
            new OrderingHandler($log),
            new AlphabeticalBeforeB($log),
            new AlphabeticalBeforeA($log),
        ], [], 'OrderingAlphabetical');

        $mediator->send(new OrderingMessage('ok'));

        expect($log->entries)->toBe([
            'before:A',
            'before:B',
            'handler:ok',
        ]);
    });

    it('places interleaved afterThrowing inside outer after handling', function () use ($factory) {
        $log = new OrderingLog();
        $mediator = $factory->createMediator([
            new OrderingThrowingHandler($log),
            new InterleavedAroundOuter($log),
            new InterleavedAfterThrowingMid($log),
            new InterleavedAfterOuter($log),
        ], [], 'OrderingAfterThrowingInterleaved');

        expect(fn() => $mediator->send(new OrderingMessage('fail')))->toThrow(\RuntimeException::class, 'boom');

        expect($log->entries)->toBe([
            'around:0:before',
            'handler:fail',
            'afterThrowing:10:boom',
            'after:-5',
        ]);
    });

    it('lets around advice pass derived immutable context down the chain', function () use ($factory) {
        $log = new OrderingLog();
        $mediator = $factory->createMediator([
            new OrderingHandler($log),
            new ContextPropagatingOuterAspect($log),
            new ContextObservingInnerAspect($log),
        ], [], 'OrderingDerivedContext');

        $mediator->send(new OrderingMessage('ok'));

        expect($log->entries)->toBe([
            'context:outer-before:none',
            'context:inner-before:propagated',
            'handler:ok',
            'context:inner-after:propagated',
            'context:outer-after:none',
        ]);
    });
}
