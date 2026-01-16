<?php

use Ultra\Aop\Interceptor;

require_once __DIR__ . '/../Stack.php';

test('intercept function call with before advice', function () {
    $stack = new Stack();

    $target = function (string $value) use ($stack) {
        $stack->push($value);
        return 'result';
    };

    $interceptor = new Interceptor($target);
    $interceptor->before(fn() => $stack->push('before'));

    $result = $interceptor->call('target');

    expect($result)->toBe('result');
    expect($stack->stack)->toBe(['before', 'target']);
});

test('intercept method call with before advice', function () {
    $stack = new Stack();

    $object = new class($stack) {
        public function __construct(private Stack $stack) {}

        public function execute(string $value): string {
            $this->stack->push($value);
            return 'result';
        }
    };

    $interceptor = new Interceptor([$object, 'execute']);
    $interceptor->before(fn() => $stack->push('before'));

    $result = $interceptor->call('target');

    expect($result)->toBe('result');
    expect($stack->stack)->toBe(['before', 'target']);
});

test('around advice', function () {
    $stack = new Stack();

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $interceptor = new Interceptor($target);
    $interceptor->around(function (callable $proceed) use ($stack) {
        $stack->push('before');
        $result = $proceed();
        $stack->push('after');
        return $result . '_modified';
    });

    $result = $interceptor->call();

    expect($result)->toBe('result_modified');
    expect($stack->stack)->toBe(['before', 'target', 'after']);
});

test('after returning advice', function () {
    $stack = new Stack();

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $interceptor = new Interceptor($target);
    $interceptor->afterReturning(function (string $result) use ($stack) {
        $stack->push('afterReturning:' . $result);
    });

    $interceptor->call();

    expect($stack->stack)->toBe(['target', 'afterReturning:result']);
});

test('after throwing advice', function () {
    $stack = new Stack();

    $target = function () use ($stack) {
        $stack->push('target');
        throw new \Exception('error');
    };

    $interceptor = new Interceptor($target);
    $interceptor->afterThrowing(function (\Throwable $e) use ($stack) {
        $stack->push('afterThrowing:' . $e->getMessage());
    });

    try {
        $interceptor->call();
    } catch (\Exception $e) {
        expect($e->getMessage())->toBe('error');
    }

    expect($stack->stack)->toBe(['target', 'afterThrowing:error']);
});

test('after advice (finally)', function () {
    $stack = new Stack();

    $target = function (bool $throw) use ($stack) {
        $stack->push('target');
        if ($throw) {
            throw new \Exception('error');
        }
        return 'result';
    };

    $interceptor = new Interceptor($target);
    $interceptor->after(fn() => $stack->push('after'));

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

    $target = function (bool $throw) use ($stack) {
        $stack->push('target');
        if ($throw) {
            throw new \Exception('error');
        }
        return 'result';
    };

    $interceptor = new Interceptor($target);
    $interceptor
        ->before(fn() => $stack->push('before'))
        ->around(function (callable $proceed, bool $throw) use ($stack) {
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
        })
        ->afterReturning(fn($result) => $stack->push('afterReturning:' . $result))
        ->afterThrowing(fn($e) => $stack->push('afterThrowing:' . $e->getMessage()))
        ->after(fn() => $stack->push('after'));

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

    $target = fn() => $stack->push('target');

    $interceptor = new Interceptor($target);
    $interceptor
        ->before(fn() => $stack->push('before1'))
        ->before(fn() => $stack->push('before2'))
        ->before(fn() => $stack->push('before3'));

    $interceptor->call();

    expect($stack->stack)->toBe(['before1', 'before2', 'before3', 'target']);
});

test('multiple around advices wrap correctly', function () {
    $stack = new Stack();

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $interceptor = new Interceptor($target);
    $interceptor
        ->around(function (callable $proceed) use ($stack) {
            $stack->push('around1_before');
            $result = $proceed();
            $stack->push('around1_after');
            return $result . '_1';
        })
        ->around(function (callable $proceed) use ($stack) {
            $stack->push('around2_before');
            $result = $proceed();
            $stack->push('around2_after');
            return $result . '_2';
        });

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
