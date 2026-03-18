<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

/**
 * Represents a single priority "layer" in the advice onion.
 */
final readonly class AdviceLayer
{
    /**
     * @param list<AdviceDescriptor> $befores
     * @param list<AdviceDescriptor> $arounds
     * @param list<AdviceDescriptor> $afterReturnings
     * @param list<AdviceDescriptor> $afterThrowings
     * @param list<AdviceDescriptor> $afters
     */
    public function __construct(
        public array $befores,
        public array $arounds,
        public array $afterReturnings,
        public array $afterThrowings,
        public array $afters,
    ) {}
}
