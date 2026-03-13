<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler;

final class DispatchPlanBuilder
{
    public function __construct(
        private readonly AdviceOrdering $ordering = new AdviceOrdering()
    ) {}

    /**
     * @param list<string>           $depServiceIds
     * @param list<AdviceDescriptor> $advices
     */
    public function build(
        string $serviceId,
        string $handlerMethod,
        array $depServiceIds,
        array $advices,
    ): DispatchPlan {
        $grouped = $this->ordering->groupByPriority($advices);
        $priorities = array_keys($grouped);
        rsort($priorities, SORT_NUMERIC); // higher priority = deeper

        $allArounds = $this->ordering->flattenByType($grouped, AdviceType::Around);

        // Collect all dep indices claimed by parameter-providing around advices.
        // Each claimed index maps to a generated variable name $_p{depIndex}.
        $claimedVarNames = []; // depIndex => '$_p{depIndex}'
        foreach ($allArounds as $advice) {
            foreach ($advice->claimedDepIndices as $depIdx) {
                $claimedVarNames[$depIdx] = '$_p'.$depIdx;
            }
        }

        $layers = [];
        foreach (array_reverse($priorities) as $priority) {
            $bucket = $grouped[$priority];
            $layers[] = new AdviceLayer(
                $bucket[AdviceType::Before->value],
                $bucket[AdviceType::Around->value],
                $bucket[AdviceType::AfterReturning->value],
                $bucket[AdviceType::AfterThrowing->value],
                $bucket[AdviceType::After->value],
            );
        }

        return new DispatchPlan(
            $serviceId,
            $handlerMethod,
            $depServiceIds,
            $layers,
            $claimedVarNames,
        );
    }
}
