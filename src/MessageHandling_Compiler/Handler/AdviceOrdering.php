<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

/**
 * Encapsulates advice ordering and grouping rules.
 *
 * Ordering rules:
 *  - Lower priority = outer layer; higher priority = deeper layer.
 *  - Within the same priority and type, order deterministically by aspect class name,
 *    then method, then service ID.
 */
final class AdviceOrdering
{
    /**
     * @param list<AdviceDescriptor> $advices
     *
     * @return array<int, array<string, list<AdviceDescriptor>>>
     */
    public function groupByPriority(array $advices): array
    {
        $grouped = [];

        foreach ($advices as $advice) {
            $priority = $advice->priority;
            if (! isset($grouped[$priority])) {
                $grouped[$priority] = [
                    AdviceType::Before->value => [],
                    AdviceType::Around->value => [],
                    AdviceType::AfterReturning->value => [],
                    AdviceType::AfterThrowing->value => [],
                    AdviceType::After->value => [],
                ];
            }
            $grouped[$priority][$advice->type->value][] = $advice;
        }

        foreach ($grouped as $priority => $bucket) {
            foreach ($bucket as $type => $list) {
                $grouped[$priority][$type] = $this->sortWithinType($list);
            }
        }

        return $grouped;
    }

    /**
     * @param array<int, array<string, list<AdviceDescriptor>>> $grouped
     *
     * @return list<AdviceDescriptor>
     */
    public function flattenByType(array $grouped, AdviceType $type): array
    {
        $result = [];
        foreach ($grouped as $bucket) {
            foreach ($bucket[$type->value] as $advice) {
                $result[] = $advice;
            }
        }

        return $result;
    }

    /**
     * Deterministic ordering within the same type and priority.
     *
     * @param list<AdviceDescriptor> $advices
     *
     * @return list<AdviceDescriptor>
     */
    private function sortWithinType(array $advices): array
    {
        usort($advices, static function (AdviceDescriptor $a, AdviceDescriptor $b): int {
            $cmp = $a->className <=> $b->className;
            if (0 !== $cmp) {
                return $cmp;
            }
            $cmp = $a->method <=> $b->method;
            if (0 !== $cmp) {
                return $cmp;
            }

            return $a->serviceId <=> $b->serviceId;
        });

        return $advices;
    }
}
