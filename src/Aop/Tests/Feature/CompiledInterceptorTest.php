<?php

use Ultra\Aop\Interceptor;

require_once __DIR__ . '/../Stack.php';

test('compiled interceptor with before advice', function () {
    $stack = new Stack();

    $target = function (string $value) use ($stack) {
        $stack->push($value);
        return 'result';
    };

    $interceptor = new Interceptor($target);
    $interceptor->before(fn() => $stack->push('before'));
    $interceptor->compile();

    $result = $interceptor->call('target');

    expect($result)->toBe('result')
        ->and($stack->stack)->toBe(['before', 'target']);
});

test('compiled interceptor with all advice types', function () {
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
        ->after(fn() => $stack->push('after'))
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

test('compiled interceptor caching', function () {
    $cacheDir = __DIR__ . '/../../../../var/aop_cache_test_compiled';
    if (is_dir($cacheDir)) {
        exec('rm -rf ' . escapeshellarg($cacheDir));
    }

    $stack = new Stack();
    $target = fn() => $stack->push('target');

    $interceptor = new Interceptor($target);
    $interceptor->before(fn() => $stack->push('before'));
    $interceptor->compile($cacheDir);

    // Check cache file exists
    $files = glob($cacheDir . '/CompiledInterceptor_*.php');
    expect(count($files))->toBe(1);

    $cacheFile = $files[0];
    $code = file_get_contents($cacheFile);

    // Verify generated code structure
    expect($code)->toContain('class CompiledInterceptor_');
    expect($code)->toContain('public function call(');
    expect($code)->toContain('($this->beforeAdvices[0])()');
    expect($code)->not->toContain('foreach');
    expect($code)->not->toContain('array_reverse');

    // Test it works
    $interceptor->call();
    expect($stack->stack)->toBe(['before', 'target']);

    // Second compile with same config should reuse cache
    $mtime = filemtime($cacheFile);

    $interceptor2 = new Interceptor($target);
    $interceptor2->before(fn() => $stack->push('before2'));
    $interceptor2->compile($cacheDir);

    // Should reuse the cached file (same hash/config)
    expect(filemtime($cacheFile))->toBe($mtime);

    // Cleanup
    exec('rm -rf ' . escapeshellarg($cacheDir));
});

test('compiled interceptor with multiple around advices', function () {
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
        })
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

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $interceptor = new Interceptor($target);
    $interceptor
        ->afterReturning(fn($result) => $stack->push('afterReturning:' . $result))
        ->after(fn() => $stack->push('after'))
        ->compile();

    $interceptor->call();

    expect($stack->stack)->toBe(['target', 'afterReturning:result', 'after']);
});

test('compiled interceptor with no advices', function () {
    $stack = new Stack();

    $target = function () use ($stack) {
        $stack->push('target');
        return 'result';
    };

    $interceptor = new Interceptor($target);
    $interceptor->compile();

    $result = $interceptor->call();

    expect($result)->toBe('result');
    expect($stack->stack)->toBe(['target']);
});

test('generated code has minimal overhead', function () {
    $stack = new Stack();
    $target = fn() => $stack->push('target');

    $interceptor = new Interceptor($target);
    $interceptor->before(fn() => $stack->push('before'));
    $interceptor->compile();

    // Compiled version should work identically
    $interceptor->call();
    expect($stack->stack)->toBe(['before', 'target']);
});
