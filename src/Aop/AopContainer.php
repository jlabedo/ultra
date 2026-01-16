<?php

declare(strict_types=1);
namespace Ultra\Aop;

use Ultra\Aop\Attribute\Advice;
use Ultra\Aop\Attribute\After;
use Ultra\Aop\Attribute\AfterReturning;
use Ultra\Aop\Attribute\AfterThrowing;
use Ultra\Aop\Attribute\Around;
use Ultra\Aop\Attribute\Before;

class AopContainer
{
    /** @var InterceptorBuilder[] */
    private array $interceptors = [];

    private array $interceptorInstances = [];
    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir ?? dirname(__DIR__, 2) . '/var/aop_cache';
    }

    public function interceptor(object $object, string $methodName): InterceptorBuilder
    {
        return $this->interceptors[] = new InterceptorBuilder($object, $methodName);
    }

    public function compile(): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        $targetClasses = [];
        foreach ($this->interceptors as $builder) {
            foreach ($builder->advices as $advice) {
                [$class, $method] = explode('::', $advice->pointcut);
                $type = match (true) {
                    $advice instanceof Before => 'before',
                    $advice instanceof After => 'after',
                    $advice instanceof AfterReturning => 'afterReturning',
                    $advice instanceof AfterThrowing => 'afterThrowing',
                    $advice instanceof Around => 'around',
                };
                $targetClasses[$class][$method][$type][] = [
                    'object' => $builder->interceptor,
                    'method' => $builder->methodName,
                ];
            }
        }

        $generator = new ProxyGenerator();
        foreach ($targetClasses as $targetClass => $methods) {
            $interceptorClass = 'Interceptor_' . md5($targetClass);
            $safeClassName = str_replace(['\\', "\0", '/', ':', '$', '@'], '_', $targetClass);
            $cacheFile = $this->cacheDir . '/' . $safeClassName . 'Interceptor.php';

            $code = $generator->generate($targetClass, $interceptorClass, $methods);
            file_put_contents($cacheFile, $code);
            require_once $cacheFile;

            $advices = [];
            foreach ($methods as $method => $adviceTypes) {
                foreach ($adviceTypes as $type => $adviceList) {
                    foreach ($adviceList as $index => $advice) {
                        $advices[$method][$type][$index] = $advice['object'];
                    }
                }
            }

            $this->interceptorInstances[$targetClass] = new $interceptorClass($advices);
        }
    }

    /**
     * Invoke a method on a target object with AOP interception
     */
    public function invoke(object $target, string $method, array $args = []): mixed
    {
        $class = get_class($target);

        if (!isset($this->interceptorInstances[$class])) {
            return $target->$method(...$args);
        }

        return $this->interceptorInstances[$class]->invoke($target, $method, $args);
    }

    /**
     * Get the interceptor instance for a class (for direct usage in mediator/bus)
     */
    public function getInterceptor(string $class): ?object
    {
        return $this->interceptorInstances[$class] ?? null;
    }

    /**
     * @deprecated Use invoke() instead. This method is kept for backward compatibility.
     */
    public function create(string $class, mixed ...$args): object
    {
        return new $class(...$args);
    }
}