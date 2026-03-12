<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler;

/**
 * Immutable plan describing a dispatch method to be rendered.
 *
 * @phpstan-type AdviceBuckets array<int, array<string, list<AdviceDescriptor>>>
 */
final readonly class DispatchPlan
{
    /**
     * @param list<string>           $depServiceIds
     * @param list<int>              $priorities      priorities in descending order (higher = deeper)
     * @param array<int, string>     $claimedVarNames depIndex => '$_p{depIndex}'
     * @param array<int, array<string, list<AdviceDescriptor>>> $groupedAdvices
     */
    public function __construct(
        public string $serviceId,
        public string $handlerMethod,
        public array $depServiceIds,
        public array $priorities,
        public array $claimedVarNames,
        public array $groupedAdvices,
    ) {
    }
}
