<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler;

/**
 * Immutable plan describing a dispatch method to be rendered.
 */
final readonly class DispatchPlan
{
    /**
     * @param list<string>       $depServiceIds
     * @param list<AdviceLayer>  $layers          ordered outer → inner (lower priority → higher)
     * @param array<int, string> $claimedVarNames depIndex => '$_p{depIndex}'
     */
    public function __construct(
        public string $serviceId,
        public string $handlerMethod,
        public array $depServiceIds,
        public array $layers,
        public array $claimedVarNames,
    ) {}
}
