<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

/**
 * Immutable description of a message handler for code generation.
 */
final readonly class HandlerDescriptor
{
    /**
     * @param list<string>           $depServiceIds
     * @param list<AdviceDescriptor> $advices
     */
    public function __construct(
        public string $serviceId,
        public string $method,
        public array $depServiceIds,
        public array $advices = [],
        public int $priority = 0,
        public string $className = '',
        public bool $returnsVoid = false,
    ) {}

    public function withMethod(string $method): self
    {
        return clone($this, [
            'method' => $method,
        ]);
    }

    /** @param list<string> $depServiceIds */
    public function withDepServiceIds(array $depServiceIds): self
    {
        return clone($this, [
            'depServiceIds' => $depServiceIds,
        ]);
    }

    /** @param list<AdviceDescriptor> $advices */
    public function withAdvices(array $advices): self
    {
        return clone($this, [
            'advices' => $advices,
        ]);
    }

    public function withPriority(int $priority): self
    {
        return clone($this, [
            'priority' => $priority,
        ]);
    }

    public function withClassName(string $className): self
    {
        return clone($this, [
            'className' => $className,
        ]);
    }

    public function withReturnsVoid(bool $returnsVoid): self
    {
        return clone($this, [
            'returnsVoid' => $returnsVoid,
        ]);
    }
}
