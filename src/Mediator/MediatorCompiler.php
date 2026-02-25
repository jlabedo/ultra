<?php

declare(strict_types=1);

namespace Ultra\Mediator;

use Psr\Container\ContainerInterface;
use Ultra\Mediator\Attribute\Handles;

final class MediatorCompiler
{
    /** @var list<class-string> */
    private array $handlerClasses = [];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @param list<class-string> $classes
     */
    public function registerHandlers(array $classes): static
    {
        $this->handlerClasses = array_merge($this->handlerClasses, $classes);

        return $this;
    }

    public function compile(): Mediator
    {
        $handlers = [];

        foreach ($this->handlerClasses as $class) {
            $ref = new \ReflectionClass($class);

            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(Handles::class) === []) {
                    continue;
                }

                $params = $method->getParameters();
                if ($params === []) {
                    continue;
                }

                $firstType = $params[0]->getType();
                if (!$firstType instanceof \ReflectionNamedType || $firstType->isBuiltin()) {
                    continue;
                }

                $messageClass = $firstType->getName();
                $serviceClasses = $this->extractServiceClasses(array_slice($params, 1), $method->getName());

                $handlers[$messageClass] = $this->buildClosure($class, $method->getName(), $serviceClasses);
            }
        }

        return new Mediator($handlers);
    }

    /**
     * @param list<\ReflectionParameter> $params
     * @return list<class-string>
     */
    private function extractServiceClasses(array $params, string $methodName): array
    {
        $classes = [];

        foreach ($params as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new \LogicException(sprintf(
                    'Parameter "$%s" of handler method "%s" must have a non-builtin type for container resolution.',
                    $param->getName(),
                    $methodName,
                ));
            }
            $classes[] = $type->getName();
        }

        return $classes;
    }

    /**
     * @param list<class-string> $serviceClasses
     */
    private function buildClosure(string $handlerClass, string $method, array $serviceClasses): \Closure
    {
        $container = $this->container;

        return static function (object $message) use ($container, $handlerClass, $method, $serviceClasses): mixed {
            $handler = $container->get($handlerClass);
            $services = array_map(static fn(string $class) => $container->get($class), $serviceClasses);

            return $handler->$method($message, ...$services);
        };
    }
}
