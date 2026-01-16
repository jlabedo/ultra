<?php

use Ultra\Aop\CallableReference;
use Ultra\Aop\Interceptor;
use Ultra\Aop\Tests\ClosureCallable;
use Ultra\Aop\Tests\TestContainer;

require_once __DIR__ . '/../Stack.php';

test('compiled interceptor with before advice', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = function (string $value) use ($stack) {
        $stack->push($value);
        return 'result';
    };

    $container->set('target', new ClosureCallable($target));
    $container->set('before', new ClosureCallable(fn() => $stack->push('before')));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor->before(CallableReference::fromContainer('before', ClosureCallable::class, 'invoke'));
    $interceptor->compile();

    $result = $interceptor->call('target');

    expect($result)->toBe('result')
        ->and($stack->stack)->toBe(['before', 'target']);
});

test('compiled interceptor with all advice types', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = function (bool $throw) use ($stack) {
        $stack->push('target');
        if ($throw) {
            throw new \Exception('error');
        }
        return 'result';
    };

    $around = function (callable $proceed, bool $throw) use ($stack) {
        $stack->push('around_before');
        try {
            $result = $proceed($throw);
            $stack->push('around_after');
            return $result . '_around';
        } catch (\Throwable $e) {
            $stack->push('around_catch');
            throw $e;
        } finally {
            $stack->push('around_finally');
        }
    };

    $container->set('target', new ClosureCallable($target));
    $container->set('before', new ClosureCallable(fn() => $stack->push('before')));
    $container->set('around', new ClosureCallable($around));
    $container->set('afterReturning', new ClosureCallable(fn($result) => $stack->push('afterReturning:' . $result)));
    $container->set('afterThrowing', new ClosureCallable(fn($e) => $stack->push('afterThrowing:' . $e->getMessage())));
    $container->set('after', new ClosureCallable(fn() => $stack->push('after')));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor
        ->before(CallableReference::fromContainer('before', ClosureCallable::class, 'invoke'))
        ->around(CallableReference::fromContainer('around', ClosureCallable::class, 'invoke'))
        ->afterReturning(CallableReference::fromContainer('afterReturning', ClosureCallable::class, 'invoke'))
        ->afterThrowing(CallableReference::fromContainer('afterThrowing', ClosureCallable::class, 'invoke'))
        ->after(CallableReference::fromContainer('after', ClosureCallable::class, 'invoke'))
        ->compile();

    // Success case
    $result = $interceptor->call(false);
    expect($result)->toBe('result_around');
    expect($stack->stack)->toBe([
        'before',
        'around_before',
        'target',
        'around_after',
        'around_finally',
        'afterReturning:result_around',
        'after',
    ]);

    // Exception case
    $stack->reset();
    try {
        $interceptor->call(true);
    } catch (\Exception $e) {
        expect($e->getMessage())->toBe('error');
    }
    expect($stack->stack)->toBe([
        'before',
        'around_before',
        'target',
        'around_catch',
        'around_finally',
        'afterThrowing:error',
        'after',
    ]);
});

test('compiled interceptor with multiple around advices', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $around1 = function (callable $proceed) use ($stack) {
        $stack->push('around1_before');
        $result = $proceed();
        $stack->push('around1_after');
        return $result . '_1';
    };

    $around2 = function (callable $proceed) use ($stack) {
        $stack->push('around2_before');
        $result = $proceed();
        $stack->push('around2_after');
        return $result . '_2';
    };

    $container->set('target', new ClosureCallable($target));
    $container->set('around1', new ClosureCallable($around1));
    $container->set('around2', new ClosureCallable($around2));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor
        ->around(CallableReference::fromContainer('around1', ClosureCallable::class, 'invoke'))
        ->around(CallableReference::fromContainer('around2', ClosureCallable::class, 'invoke'))
        ->compile();

    $result = $interceptor->call();

    expect($result)->toBe('result_2_1');
    expect($stack->stack)->toBe([
        'around1_before',
        'around2_before',
        'target',
        'around2_after',
        'around1_after',
    ]);
});

test('compiled interceptor with only after advices', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $container->set('target', new ClosureCallable($target));
    $container->set('afterReturning', new ClosureCallable(fn($result) => $stack->push('afterReturning:' . $result)));
    $container->set('after', new ClosureCallable(fn() => $stack->push('after')));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor
        ->afterReturning(CallableReference::fromContainer('afterReturning', ClosureCallable::class, 'invoke'))
        ->after(CallableReference::fromContainer('after', ClosureCallable::class, 'invoke'))
        ->compile();

    $interceptor->call();

    expect($stack->stack)->toBe(['target', 'afterReturning:result', 'after']);
});

test('compiled interceptor with no advices', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $container->set('target', new ClosureCallable($target));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor->compile();

    $result = $interceptor->call();

    expect($result)->toBe('result');
    expect($stack->stack)->toBe(['target']);
});

test('generated code has minimal overhead', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = fn() => $stack->push('target');

    $container->set('target', new ClosureCallable($target));
    $container->set('before', new ClosureCallable(fn() => $stack->push('before')));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor->before(CallableReference::fromContainer('before', ClosureCallable::class, 'invoke'));
    $interceptor->compile();

    // Compiled version should work identically
    $interceptor->call();
    expect($stack->stack)->toBe(['before', 'target']);
});
