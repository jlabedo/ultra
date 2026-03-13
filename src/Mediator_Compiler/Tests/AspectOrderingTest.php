<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler\Tests;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Mediator\Attribute\After;
use Ultra\Mediator\Attribute\AfterReturning;
use Ultra\Mediator\Attribute\AfterThrowing;
use Ultra\Mediator\Attribute\Around;
use Ultra\Mediator\Attribute\Before;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\MediatorInterface;
use Ultra\Mediator_Compiler\MediatorCompilerPass;

// ---------------------------------------------------------------------------
// Ordering fixtures
// ---------------------------------------------------------------------------

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
    public function handle(OrderingMessage $msg): string
    {
        $this->log->entries[] = 'handler:'.$msg->payload;

        return $msg->payload;
    }
}

final class OrderingAspect
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 20)]
    public function before(object $message, array $context): void
    {
        $this->log->entries[] = 'before:20';
    }

    #[Around(priority: 10)]
    public function around(callable $proceed, object $message, array $context): mixed
    {
        $this->log->entries[] = 'around:10:before';
        $result = $proceed($message, $context);
        $this->log->entries[] = 'around:10:after';

        return $result;
    }

    #[After(priority: 5)]
    public function after(object $message, array $context): void
    {
        $this->log->entries[] = 'after:5';
    }
}

final class SamePriorityOrderingAspect
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, array $context): void
    {
        $this->log->entries[] = 'before:10';
    }

    #[Around(priority: 10)]
    public function around(callable $proceed, object $message, array $context): mixed
    {
        $this->log->entries[] = 'around:10:before';
        $result = $proceed($message, $context);
        $this->log->entries[] = 'around:10:after';

        return $result;
    }

    #[AfterReturning(priority: 10)]
    public function afterReturning(object $message, array $context, mixed $result): void
    {
        $this->log->entries[] = 'afterReturning:10:'.$result;
    }

    #[AfterThrowing(priority: 10)]
    public function afterThrowing(object $message, array $context, \Throwable $e): void
    {
        $this->log->entries[] = 'afterThrowing:10:'.$e->getMessage();
    }

    #[After(priority: 10)]
    public function after(object $message, array $context): void
    {
        $this->log->entries[] = 'after:10';
    }
}

final class OrderingThrowingHandler
{
    public function __construct(public OrderingLog $log) {}

    #[Handles]
    public function handle(OrderingMessage $msg): string
    {
        $this->log->entries[] = 'handler:'.$msg->payload;

        throw new \RuntimeException('boom');
    }
}

final class InterleavedAroundOuter
{
    public function __construct(public OrderingLog $log) {}

    #[Around(priority: 0)]
    public function around(callable $proceed, object $message, array $context): mixed
    {
        $this->log->entries[] = 'around:0:before';
        $result = $proceed($message, $context);
        $this->log->entries[] = 'around:0:after';

        return $result;
    }
}

final class InterleavedBeforeMid
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, array $context): void
    {
        $this->log->entries[] = 'before:10';
    }
}

final class InterleavedAroundInner
{
    public function __construct(public OrderingLog $log) {}

    #[Around(priority: 20)]
    public function around(callable $proceed, object $message, array $context): mixed
    {
        $this->log->entries[] = 'around:20:before';
        $result = $proceed($message, $context);
        $this->log->entries[] = 'around:20:after';

        return $result;
    }
}

final class AlphabeticalBeforeA
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, array $context): void
    {
        $this->log->entries[] = 'before:A';
    }
}

final class AlphabeticalBeforeB
{
    public function __construct(public OrderingLog $log) {}

    #[Before(priority: 10)]
    public function before(object $message, array $context): void
    {
        $this->log->entries[] = 'before:B';
    }
}

final class InterleavedAfterThrowingMid
{
    public function __construct(public OrderingLog $log) {}

    #[AfterThrowing(priority: 10)]
    public function afterThrowing(object $message, array $context, \Throwable $e): void
    {
        $this->log->entries[] = 'afterThrowing:10:'.$e->getMessage();
    }
}

final class InterleavedAfterOuter
{
    public function __construct(public OrderingLog $log) {}

    #[After(priority: -5)]
    public function after(object $message, array $context): void
    {
        $this->log->entries[] = 'after:-5';
    }
}

// ---------------------------------------------------------------------------
// Ordering tests
// ---------------------------------------------------------------------------

test('priority ordering places before/around/after in the correct onion layers', function () {
    $log = new OrderingLog();
    $container = new ContainerBuilder();
    $container->setDefinition(
        OrderingHandler::class,
        new Definition(OrderingHandler::class, [$log])->addTag('mediator.handler')
    );
    $container->setDefinition(
        OrderingAspect::class,
        new Definition(OrderingAspect::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );

    $container->addCompilerPass(new MediatorCompilerPass(dirname(__DIR__, 3).'/var/mediator_compiler_tests', 'CompiledMediatorOrdering1'));
    $container->compile();

    $mediator = $container->get(MediatorInterface::class);
    $mediator->send(new OrderingMessage('ok'));

    expect($log->entries)->toBe([
        'around:10:before',
        'before:20',
        'handler:ok',
        'around:10:after',
        'after:5',
    ]);
});

test('same priority follows before → around → handler → after returning → after throwing → after', function () {
    $log = new OrderingLog();
    $container = new ContainerBuilder();
    $container->setDefinition(
        OrderingHandler::class,
        new Definition(OrderingHandler::class, [$log])->addTag('mediator.handler')
    );
    $container->setDefinition(
        SamePriorityOrderingAspect::class,
        new Definition(SamePriorityOrderingAspect::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );

    $container->addCompilerPass(new MediatorCompilerPass(dirname(__DIR__, 3).'/var/mediator_compiler_tests', 'CompiledMediatorOrdering2'));
    $container->compile();

    $mediator = $container->get(MediatorInterface::class);
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

test('same priority runs afterThrowing before after', function () {
    $log = new OrderingLog();
    $container = new ContainerBuilder();
    $container->setDefinition(
        OrderingThrowingHandler::class,
        new Definition(OrderingThrowingHandler::class, [$log])->addTag('mediator.handler')
    );
    $container->setDefinition(
        SamePriorityOrderingAspect::class,
        new Definition(SamePriorityOrderingAspect::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );

    $container->addCompilerPass(new MediatorCompilerPass(dirname(__DIR__, 3).'/var/mediator_compiler_tests', 'CompiledMediatorOrdering3'));
    $container->compile();

    $mediator = $container->get(MediatorInterface::class);

    expect(fn () => $mediator->send(new OrderingMessage('fail')))->toThrow(\RuntimeException::class, 'boom');

    expect($log->entries)->toBe([
        'before:10',
        'around:10:before',
        'handler:fail',
        'afterThrowing:10:boom',
        'after:10',
    ]);
});

test('interleaved priorities nest correctly across layers', function () {
    $log = new OrderingLog();
    $container = new ContainerBuilder();
    $container->setDefinition(
        OrderingHandler::class,
        new Definition(OrderingHandler::class, [$log])->addTag('mediator.handler')
    );
    $container->setDefinition(
        InterleavedAroundOuter::class,
        new Definition(InterleavedAroundOuter::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );
    $container->setDefinition(
        InterleavedBeforeMid::class,
        new Definition(InterleavedBeforeMid::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );
    $container->setDefinition(
        InterleavedAroundInner::class,
        new Definition(InterleavedAroundInner::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );
    $container->setDefinition(
        InterleavedAfterOuter::class,
        new Definition(InterleavedAfterOuter::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );

    $container->addCompilerPass(new MediatorCompilerPass(dirname(__DIR__, 3).'/var/mediator_compiler_tests', 'CompiledMediatorOrdering4'));
    $container->compile();

    $mediator = $container->get(MediatorInterface::class);
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

test('same type and priority are ordered by class name', function () {
    $log = new OrderingLog();
    $container = new ContainerBuilder();
    $container->setDefinition(
        OrderingHandler::class,
        new Definition(OrderingHandler::class, [$log])->addTag('mediator.handler')
    );
    $container->setDefinition(
        AlphabeticalBeforeB::class,
        new Definition(AlphabeticalBeforeB::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );
    $container->setDefinition(
        AlphabeticalBeforeA::class,
        new Definition(AlphabeticalBeforeA::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );

    $container->addCompilerPass(new MediatorCompilerPass(dirname(__DIR__, 3).'/var/mediator_compiler_tests', 'CompiledMediatorOrdering5'));
    $container->compile();

    $mediator = $container->get(MediatorInterface::class);
    $mediator->send(new OrderingMessage('ok'));

    expect($log->entries)->toBe([
        'before:A',
        'before:B',
        'handler:ok',
    ]);
});

test('interleaved priorities place afterThrowing inside outer after', function () {
    $log = new OrderingLog();
    $container = new ContainerBuilder();
    $container->setDefinition(
        OrderingThrowingHandler::class,
        new Definition(OrderingThrowingHandler::class, [$log])->addTag('mediator.handler')
    );
    $container->setDefinition(
        InterleavedAroundOuter::class,
        new Definition(InterleavedAroundOuter::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );
    $container->setDefinition(
        InterleavedAfterThrowingMid::class,
        new Definition(InterleavedAfterThrowingMid::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );
    $container->setDefinition(
        InterleavedAfterOuter::class,
        new Definition(InterleavedAfterOuter::class, [$log])->addTag('mediator.aspect')->setPublic(true)
    );

    $container->addCompilerPass(new MediatorCompilerPass(dirname(__DIR__, 3).'/var/mediator_compiler_tests', 'CompiledMediatorOrdering6'));
    $container->compile();

    $mediator = $container->get(MediatorInterface::class);

    expect(fn () => $mediator->send(new OrderingMessage('fail')))->toThrow(\RuntimeException::class, 'boom');

    expect($log->entries)->toBe([
        'around:0:before',
        'handler:fail',
        'afterThrowing:10:boom',
        'after:-5',
    ]);
});
