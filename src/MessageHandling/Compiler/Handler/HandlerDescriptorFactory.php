<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

use Ultra\MessageHandling\Attribute\AdvicePhase;
use Ultra\MessageHandling\Attribute\Asynchronous;

final class HandlerDescriptorFactory
{
    public function __construct(
        private AdviceCollector $adviceCollector,
    ) {}

    /**
     * @param class-string $className
     */
    public function createServiceHandler(
        string $serviceId,
        string $className,
        \ReflectionMethod $method,
    ): ?HandlerDescriptor {
        $messageClass = $this->resolveMessageClass($method);
        if (null === $messageClass) {
            return null;
        }

        return $this->create($serviceId, $className, $method, $messageClass, skipFirstParameter: true);
    }

    /**
     * @param class-string $className
     */
    public function createSelfHandler(string $className, \ReflectionMethod $method): HandlerDescriptor
    {
        return $this->create(
            HandlerDescriptor::SELF_SERVICE_ID,
            $className,
            $method,
            $className,
            skipFirstParameter: false,
        );
    }

    /**
     * @return list<string>
     */
    public function resolveDepServiceIds(\ReflectionMethod $method, bool $skipFirstParameter = true): array
    {
        $ids = [];
        foreach (array_slice($method->getParameters(), $skipFirstParameter ? 1 : 0) as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
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

    /**
     * @param class-string $className
     * @param class-string $messageClass
     */
    private function create(
        string $serviceId,
        string $className,
        \ReflectionMethod $method,
        string $messageClass,
        bool $skipFirstParameter,
    ): HandlerDescriptor {
        $depServiceIds = $this->resolveDepServiceIds($method, $skipFirstParameter);
        $asyncAttrs = $method->getAttributes(Asynchronous::class);
        $isAsync = [] !== $asyncAttrs;
        $asyncChannel = $isAsync ? $asyncAttrs[0]->newInstance()->channel : 'default';
        $isSelfHandler = HandlerDescriptor::SELF_SERVICE_ID === $serviceId;

        // For sync handlers, Execute is the only phase (dispatch = execute).
        // For async handlers, we collect Execute-phase advices here;
        // Dispatch-phase advices are collected separately in the compiler pass.
        $context = new CompileTimeHandlerMatchContext(
            $method,
            $messageClass,
            $isAsync,
            $isSelfHandler,
            AdvicePhase::Execute,
        );
        $advices = $this->adviceCollector->collect($context);

        return new HandlerDescriptor(
            serviceId: $serviceId,
            method: $method,
            depServiceIds: $depServiceIds,
            advices: $advices,
            className: $className,
            returnsVoid: $this->returnsVoid($method),
            messageClass: $messageClass,
            isAsync: $isAsync,
            asyncChannel: $asyncChannel,
        );
    }

    /**
     * @return class-string|null
     */
    private function resolveMessageClass(\ReflectionMethod $method): ?string
    {
        $params = $method->getParameters();
        if ([] === $params) {
            return null;
        }

        $firstType = $params[0]->getType();
        if (!$firstType instanceof \ReflectionNamedType || $firstType->isBuiltin()) {
            return null;
        }
        /** @var class-string */
        return $firstType->getName();
    }
}
