<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

final class HandlerDescriptorFactory
{
    public function create(
        string $serviceId,
        string $className,
        \ReflectionMethod $method,
        bool $skipFirstParameter = true,
        int $priority = 0,
    ): HandlerDescriptor {
        $depServiceIds = $this->resolveDepServiceIds($method, $skipFirstParameter);

        return (new HandlerDescriptor($serviceId, $method->getName(), $depServiceIds))
            ->withClassName($className)
            ->withPriority($priority)
            ->withReturnsVoid($this->returnsVoid($method));
    }

    /**
     * @return list<string>
     */
    public function resolveDepServiceIds(\ReflectionMethod $method, bool $skipFirstParameter = true): array
    {
        $ids = [];
        foreach (array_slice($method->getParameters(), $skipFirstParameter ? 1 : 0) as $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new \LogicException(sprintf(
                    'Parameter "$%s" of handler method "%s::%s" must have a non-builtin type.',
                    $param->getName(),
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ));
            }
            $ids[] = $type->getName();
        }

        return $ids;
    }

    public function returnsVoid(\ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        return $returnType instanceof \ReflectionNamedType && 'void' === $returnType->getName();
    }
}
