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

    private array $proxies = [];
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
            $proxyClass = 'Proxy_' . md5($targetClass);
            $safeClassName = str_replace(['\\', "\0", '/', ':', '$', '@'], '_', $targetClass);
            $cacheFile = $this->cacheDir . '/' . $safeClassName . 'Proxy.php';
            
            $code = $generator->generate($targetClass, $proxyClass, $methods);
            file_put_contents($cacheFile, $code);
            require_once $cacheFile;

            $this->proxies[$targetClass] = [
                'class' => $proxyClass,
                'methods' => $methods,
            ];
        }
    }

    public function create(string $class, mixed ...$args): object
    {
        if (!isset($this->proxies[$class])) {
            return new $class(...$args);
        }

        $proxyData = $this->proxies[$class];
        $proxyClass = $proxyData['class'];
        
        $interceptors = [];
        foreach ($proxyData['methods'] as $method => $advices) {
            foreach ($advices as $type => $adviceList) {
                foreach ($adviceList as $index => $advice) {
                    $interceptors[$method][$type][$index] = $advice['object'];
                }
            }
        }

        return new $proxyClass($interceptors, ...$args);
    }
}