<?php

use Ultra\Aop\CallableReference;
use Ultra\Aop\Interceptor;
use Ultra\Aop\Tests\ClosureCallable;
use Ultra\Aop\Tests\TestContainer;

require_once __DIR__ . '/../Stack.php';

test('intercept function call with before advice', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $container->set('target', new ClosureCallable(function (string $value) use ($stack) {
        $stack->push($value);
        return 'result';
    }));
    $container->set('before', new ClosureCallable(fn() => $stack->push('before')));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor->before(CallableReference::fromContainer('before', ClosureCallable::class, 'invoke'));

    $result = $interceptor->call('target');

    expect($result)->toBe('result');
    expect($stack->stack)->toBe(['before', 'target']);
});

test('intercept method call with before advice', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $object = new class($stack) {
        public function __construct(private Stack $stack) {}

        public function execute(string $value): string {
            $this->stack->push($value);
            return 'result';
        }
    };

    $container->set('target', $object);
    $container->set('before', new ClosureCallable(fn() => $stack->push('before')));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', get_class($object), 'execute'),
        $container
    );
    $interceptor->before(CallableReference::fromContainer('before', ClosureCallable::class, 'invoke'));

    $result = $interceptor->call('target');

    expect($result)->toBe('result');
    expect($stack->stack)->toBe(['before', 'target']);
});

test('around advice', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $around = function (callable $proceed) use ($stack) {
        $stack->push('before');
        $result = $proceed();
        $stack->push('after');
        return $result . '_modified';
    };

    $container->set('target', new ClosureCallable($target));
    $container->set('around', new ClosureCallable($around));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor->around(CallableReference::fromContainer('around', ClosureCallable::class, 'invoke'));

    $result = $interceptor->call();

    expect($result)->toBe('result_modified');
    expect($stack->stack)->toBe(['before', 'target', 'after']);
});

test('after returning advice', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $container->set('target', new ClosureCallable($target));
    $container->set('afterReturning', new ClosureCallable(function (string $result) use ($stack) {
        $stack->push('afterReturning:' . $result);
    }));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor->afterReturning(CallableReference::fromContainer('afterReturning', ClosureCallable::class, 'invoke'));

    $interceptor->call();

    expect($stack->stack)->toBe(['target', 'afterReturning:result']);
});

test('after throwing advice', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = function () use ($stack) {
        $stack->push('target');
        throw new \Exception('error');
    };

    $container->set('target', new ClosureCallable($target));
    $container->set('afterThrowing', new ClosureCallable(function (\Throwable $e) use ($stack) {
        $stack->push('afterThrowing:' . $e->getMessage());
    }));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor->afterThrowing(CallableReference::fromContainer('afterThrowing', ClosureCallable::class, 'invoke'));

    try {
        $interceptor->call();
    } catch (\Exception $e) {
        expect($e->getMessage())->toBe('error');
    }

    expect($stack->stack)->toBe(['target', 'afterThrowing:error']);
});

test('after advice (finally)', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = function (bool $throw) use ($stack) {
        $stack->push('target');
        if ($throw) {
            throw new \Exception('error');
        }
        return 'result';
    };

    $container->set('target', new ClosureCallable($target));
    $container->set('after', new ClosureCallable(fn() => $stack->push('after')));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor->after(CallableReference::fromContainer('after', ClosureCallable::class, 'invoke'));

    // Success case
    $interceptor->call(false);
    expect($stack->stack)->toBe(['target', 'after']);

    // Exception case
    $stack->reset();
    try {
        $interceptor->call(true);
    } catch (\Exception $e) {
        // Expected
    }
    expect($stack->stack)->toBe(['target', 'after']);
});

test('all advice types combined', function () {
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
        ->after(CallableReference::fromContainer('after', ClosureCallable::class, 'invoke'));

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

test('multiple before advices execute in order', function () {
    $stack = new Stack();
    $container = new TestContainer();

    $target = fn() => $stack->push('target');

    $container->set('target', new ClosureCallable($target));
    $container->set('before1', new ClosureCallable(fn() => $stack->push('before1')));
    $container->set('before2', new ClosureCallable(fn() => $stack->push('before2')));
    $container->set('before3', new ClosureCallable(fn() => $stack->push('before3')));

    $interceptor = new Interceptor(
        CallableReference::fromContainer('target', ClosureCallable::class, 'invoke'),
        $container
    );
    $interceptor
        ->before(CallableReference::fromContainer('before1', ClosureCallable::class, 'invoke'))
        ->before(CallableReference::fromContainer('before2', ClosureCallable::class, 'invoke'))
        ->before(CallableReference::fromContainer('before3', ClosureCallable::class, 'invoke'));

    $interceptor->call();

    expect($stack->stack)->toBe(['before1', 'before2', 'before3', 'target']);
});

test('multiple around advices wrap correctly', function () {
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
        ->around(CallableReference::fromContainer('around2', ClosureCallable::class, 'invoke'));

    $result = $interceptor->call();

    // Around advices: array_reverse makes around2 outer, around1 inner
    // Execution: around1_before -> around2_before -> target (returns 'result')
    // -> around2_after (returns 'result_2') -> around1_after (returns 'result_2_1')
    expect($result)->toBe('result_2_1');
    expect($stack->stack)->toBe([
        'around1_before',
        'around2_before',
        'target',
        'around2_after',
        'around1_after',
    ]);
});
