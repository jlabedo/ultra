<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

/**
 * Immutable description of a message handler for code generation.
 */
final readonly class HandlerDescriptor
{
    public const SELF_SERVICE_ID = '@self';

    /**
     * @param list<string>           $depServiceIds
     * @param list<AdviceDescriptor> $advices
     */
    public function __construct(
        public string $serviceId,
        public \ReflectionMethod $method,
        public array $depServiceIds,
        public array $advices = [],
        public int $priority = 0,
        public string $className = '',
        public bool $returnsVoid = false,
        public string $messageClass = '',
        public bool $isAsync = false,
        public string $asyncChannel = 'default',
    ) {}

    /** @param list<AdviceDescriptor> $advices */
    public function withAdvices(array $advices): self
    {
        return clone ($this, [
            'advices' => $advices,
        ]);
    }

    public function withPriority(int $priority): self
    {
        return clone ($this, [
            'priority' => $priority,
        ]);
    }
}
