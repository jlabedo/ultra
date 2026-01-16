<?php

use Ultra\Aop\AopContainer;
use Ultra\Aop\Attribute\Before;

require_once __DIR__ . '/../Stack.php';
require_once __DIR__ . '/../StackIncrementer.php';

class Target extends StackIncrementer {
    public function invoke(): void
    {
        $this->stack->push('foo');
    }
}

class Interceptor extends StackIncrementer {
    public function intercept(): void
    {
        $this->stack->push('bar');
    }
}

test('full integration', function () {
    $stack = new Stack();
    $interceptor = new Interceptor($stack);

    $container = new AopContainer();
    $container->interceptor($interceptor, 'intercept')->before(Target::class, 'invoke');
    $container->compile();

    $target = new Target($stack);
    $container->invoke($target, 'invoke');

    expect($stack->stack)->toBe(['bar', 'foo']);
});

test('around', function () {
    $stack = new Stack();
    $class = new class ($stack) extends StackIncrementer {
        public function invoke(): string
        {
            return 'foo';
        }
    };
    $classReflection = new ReflectionClass($class);
    $className = $classReflection->getName();

    $interceptor = new class ($stack) extends StackIncrementer {
        public function intercept(callable $proceed): string
        {
            $this->stack->push('before');
            $result = $proceed();
            $this->stack->push('after');
            return $result . 'bar';
        }
    };

    $container = new AopContainer();
    $container->interceptor($interceptor, 'intercept')->around($className, 'invoke');
    $container->compile();

    $target = $class;
    $result = $container->invoke($target, 'invoke');

    expect($result)->toBe('foobar');
    expect($stack->stack)->toBe(['before', 'after']);
});

class TargetForAll extends StackIncrementer {
    public function invoke(bool $throw = false): string
    {
        $this->stack->push('target');
        if ($throw) {
            throw new \Exception('target exception');
        }
        return 'result';
    }
}

test('all interceptors on same method', function () {
    $stack = new Stack();
    $targetClass = TargetForAll::class;

    $interceptor = new class ($stack) extends StackIncrementer {
        public function before(): void { $this->stack->push('before'); }
        public function after(): void { $this->stack->push('after'); }
        public function afterReturning(string $res): void { $this->stack->push('afterReturning:' . $res); }
        public function afterThrowing(\Throwable $e): void { $this->stack->push('afterThrowing:' . $e->getMessage()); }
        public function around(callable $proceed, bool $throw = false): string {
            $this->stack->push('around_before');
            try {
                $res = $proceed($throw);
                $this->stack->push('around_after_returning');
                return $res . '_around';
            } catch (\Throwable $e) {
                $this->stack->push('around_after_throwing');
                throw $e;
            } finally {
                $this->stack->push('around_finally');
            }
        }
    };

    $container = new AopContainer();
    $container->interceptor($interceptor, 'before')->before($targetClass, 'invoke');
    $container->interceptor($interceptor, 'after')->after($targetClass, 'invoke');
    $container->interceptor($interceptor, 'afterReturning')->afterReturning($targetClass, 'invoke');
    $container->interceptor($interceptor, 'afterThrowing')->afterThrowing($targetClass, 'invoke');
    $container->interceptor($interceptor, 'around')->around($targetClass, 'invoke');

    $container->compile();

    $target = new TargetForAll($stack);

    // Test successful execution
    $stack->reset();
    $result = $container->invoke($target, 'invoke', [false]);

    expect($result)->toBe('result_around');
    // Expected order:
    // 1. Before
    // 2. Around (starts)
    // 3. Target
    // 4. Around (after returning)
    // 5. Around (finally)
    // 6. AfterReturning
    // 7. After
    expect($stack->stack)->toBe([
        'before',
        'around_before',
        'target',
        'around_after_returning',
        'around_finally',
        'afterReturning:result_around',
        'after',
    ]);

    // Test execution with exception
    $stack->reset();
    try {
        $container->invoke($target, 'invoke', [true]);
    } catch (\Throwable $e) {
        expect($e->getMessage())->toBe('target exception');
    }

    // Expected order with exception:
    // 1. Before
    // 2. Around (starts)
    // 3. Target (throws)
    // 4. Around (after throwing)
    // 5. Around (finally)
    // 6. AfterThrowing
    // 7. After
    expect($stack->stack)->toBe([
        'before',
        'around_before',
        'target',
        'around_after_throwing',
        'around_finally',
        'afterThrowing:target exception',
        'after',
    ]);
});
