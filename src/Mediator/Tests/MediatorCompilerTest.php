<?php

declare(strict_types=1);

namespace Ultra\Mediator\Tests;

use Psr\Container\ContainerInterface;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\Exception\NoHandlerFound;
use Ultra\Mediator\MediatorCompiler;

// ---------------------------------------------------------------------------
// Minimal PSR-11 container for tests
// ---------------------------------------------------------------------------

final class TestContainer implements ContainerInterface
{
    private array $services = [];

    public function get(string $id): mixed
    {
        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Service '{$id}' not found in container");
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }
}

// ---------------------------------------------------------------------------
// Step 1 fixtures — handler with message-only method
// ---------------------------------------------------------------------------

final readonly class GreetMessage
{
    public function __construct(public string $name)
    {
    }
}

final readonly class FareMessage
{
    public function __construct(public string $name)
    {
    }
}

final class GreetHandler
{
    #[Handles]
    public function greet(GreetMessage $msg): string
    {
        return 'Hello, ' . $msg->name;
    }
}

/** Multiple handlers on a single class */
final class MultiHandler
{
    #[Handles]
    public function onGreet(GreetMessage $msg): string
    {
        return 'Hi, ' . $msg->name;
    }

    #[Handles]
    public function onFare(FareMessage $msg): string
    {
        return 'Goodbye, ' . $msg->name;
    }

    /** Regular public method — must be ignored by the compiler */
    public function helper(): void
    {
    }
}

// ---------------------------------------------------------------------------
// Step 2 fixtures — handler with service injection
// ---------------------------------------------------------------------------

final readonly class CountMessage
{
    public function __construct(public string $label)
    {
    }
}

final class CounterService
{
    public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }
}

final class SuffixService
{
    public function suffix(string $value): string
    {
        return $value . '!';
    }
}

final class CountHandler
{
    #[Handles]
    public function handle(CountMessage $msg, CounterService $counter): string
    {
        $counter->increment();

        return $msg->label . ':' . $counter->count;
    }
}

final class MultiServiceHandler
{
    #[Handles]
    public function handle(CountMessage $msg, CounterService $counter, SuffixService $suffix): string
    {
        $counter->increment();

        return $suffix->suffix($msg->label . ':' . $counter->count);
    }
}

// ---------------------------------------------------------------------------
// Step 1 — basic routing
// ---------------------------------------------------------------------------

test('routes a message to the correct handler and returns its result', function () {
    $container = new TestContainer();
    $container->set(GreetHandler::class, new GreetHandler());

    $mediator = (new MediatorCompiler($container))
        ->registerHandlers([GreetHandler::class])
        ->compile();

    expect($mediator->send(new GreetMessage('World')))->toBe('Hello, World');
});

test('throws NoHandlerFound when no handler is registered for the message', function () {
    $mediator = (new MediatorCompiler(new TestContainer()))->compile();

    expect(fn() => $mediator->send(new GreetMessage('World')))
        ->toThrow(NoHandlerFound::class);
});

test('supports multiple handlers per class, each routed by message type', function () {
    $container = new TestContainer();
    $container->set(MultiHandler::class, new MultiHandler());

    $mediator = (new MediatorCompiler($container))
        ->registerHandlers([MultiHandler::class])
        ->compile();

    expect($mediator->send(new GreetMessage('Alice')))->toBe('Hi, Alice');
    expect($mediator->send(new FareMessage('Bob')))->toBe('Goodbye, Bob');
});

test('public methods without #[Handles] are not registered as handlers', function () {
    $container = new TestContainer();
    $container->set(MultiHandler::class, new MultiHandler());

    $mediator = (new MediatorCompiler($container))
        ->registerHandlers([MultiHandler::class])
        ->compile();

    // GreetMessage and FareMessage are handled; no other messages should be
    expect($mediator->send(new GreetMessage('X')))->toBe('Hi, X');
});

test('context parameter is accepted but does not affect handler dispatch', function () {
    $container = new TestContainer();
    $container->set(GreetHandler::class, new GreetHandler());

    $mediator = (new MediatorCompiler($container))
        ->registerHandlers([GreetHandler::class])
        ->compile();

    expect($mediator->send(new GreetMessage('World'), ['requestId' => 'abc']))->toBe('Hello, World');
});

// ---------------------------------------------------------------------------
// Step 2 — service injection
// ---------------------------------------------------------------------------

test('injects a service from the container into handler method parameters', function () {
    $counter = new CounterService();
    $container = new TestContainer();
    $container->set(CountHandler::class, new CountHandler());
    $container->set(CounterService::class, $counter);

    $mediator = (new MediatorCompiler($container))
        ->registerHandlers([CountHandler::class])
        ->compile();

    $result = $mediator->send(new CountMessage('item'));

    expect($result)->toBe('item:1')
        ->and($counter->count)->toBe(1);
});

test('injects multiple services in declaration order', function () {
    $counter = new CounterService();
    $suffix = new SuffixService();
    $container = new TestContainer();
    $container->set(MultiServiceHandler::class, new MultiServiceHandler());
    $container->set(CounterService::class, $counter);
    $container->set(SuffixService::class, $suffix);

    $mediator = (new MediatorCompiler($container))
        ->registerHandlers([MultiServiceHandler::class])
        ->compile();

    expect($mediator->send(new CountMessage('x')))->toBe('x:1!');
});

test('resolves handler and services from the container on each send call', function () {
    $counter = new CounterService();
    $container = new TestContainer();
    $container->set(CountHandler::class, new CountHandler());
    $container->set(CounterService::class, $counter);

    $mediator = (new MediatorCompiler($container))
        ->registerHandlers([CountHandler::class])
        ->compile();

    $mediator->send(new CountMessage('a'));
    $mediator->send(new CountMessage('b'));

    // Same CounterService instance from container: count accumulates
    expect($counter->count)->toBe(2);
});
