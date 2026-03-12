<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler;

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
    ) {
    }

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
}
