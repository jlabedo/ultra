<?php

declare(strict_types=1);

namespace Ultra\Mediator_Compiler;

final class DispatchPlanRenderer
{
    public function renderDispatchMethod(string $dispatchName, DispatchPlan $plan): string
    {
        $coreExpr = $this->buildHandlerExpression(
            $plan->serviceId,
            $plan->handlerMethod,
            $plan->depServiceIds,
            $plan->claimedVarNames,
            '$m',
        );

        foreach (array_reverse($plan->layers) as $layer) {
            $coreExpr = $this->applyAroundsAtPriority(
                $layer->arounds,
                $coreExpr,
                $plan->claimedVarNames,
            );
            $coreExpr = $this->wrapWithBeforeAfter(
                $coreExpr,
                $layer->befores,
                $layer->afterReturnings,
                $layer->afterThrowings,
                $layer->afters,
            );
        }

        $lines = [];
        $lines[] = '        $m = $message;';
        $lines[] = '        $c = $context;';
        $lines[] = '        return ' . $coreExpr . ';';

        $body = implode("\n", $lines);

        return <<<PHP
                private function {$dispatchName}(object \$message, array \$context): mixed
                {
            {$body}
                }
            PHP;
    }

    /**
     * Build the handler call expression (used as the innermost expression).
     *
     * @param list<string>       $depServiceIds
     * @param array<int, string> $claimedVarNames
     */
    private function buildHandlerExpression(
        string $serviceId,
        string $handlerMethod,
        array $depServiceIds,
        array $claimedVarNames,
        string $messageVar,
    ): string {
        $isSelf = $serviceId === '@self';

        $handlerArgs = [];
        if (!$isSelf) {
            $handlerArgs[] = $messageVar;
        }
        foreach ($depServiceIds as $depIdx => $depId) {
            $handlerArgs[] = isset($claimedVarNames[$depIdx])
                ? $claimedVarNames[$depIdx]
                : '$this->locator->get(' . $this->exportString($depId) . ')';
        }

        return $isSelf
            ? \sprintf('%s->%s(%s)', $messageVar, $handlerMethod, implode(', ', $handlerArgs))
            : \sprintf(
                '$this->locator->get(%s)->%s(%s)',
                $this->exportString($serviceId),
                $handlerMethod,
                implode(', ', $handlerArgs),
            );
    }

    /**
     * Wrap inner expression with around advices at the same priority.
     *
     * @param list<AdviceDescriptor> $arounds
     * @param array<int, string>     $claimedVarNames
     */
    private function applyAroundsAtPriority(
        array $arounds,
        string $innerExpr,
        array $claimedVarNames,
    ): string {
        if ($arounds === []) {
            return $innerExpr;
        }

        $inner = $innerExpr;

        // Wrap with around advices. Reverse so the earlier advice (deterministic order)
        // becomes the outermost wrapper.
        foreach (array_reverse($arounds) as $advice) {
            // Lambda params: $m and $c, plus any dep vars this advice claims.
            // Inner lambdas auto-capture claimed vars from outer lambda scopes.
            $lambdaParams = ['$m', '$c'];
            foreach ($advice->claimedDepIndices as $depIdx) {
                $lambdaParams[] = $claimedVarNames[$depIdx];
            }

            // Aspect call args: proceed lambda, message, context, and — when the
            // advice carries a non-empty runtimeConfig — a PHP literal array.
            $aspectArgs = [
                'fn(' . implode(', ', $lambdaParams) . ') => ' . $inner,
                '$m',
                '$c',
            ];
            if ($advice->runtimeConfig !== []) {
                $aspectArgs[] = $this->exportValue($advice->runtimeConfig);
            }

            $inner = sprintf(
                '$this->locator->get(%s)->%s(%s)',
                $this->exportString($advice->serviceId),
                $advice->method,
                implode(', ', $aspectArgs),
            );
        }

        return $inner;
    }

    /**
     * Wrap the inner expression with before/after-type advices for a priority.
     *
     * @param list<AdviceDescriptor> $befores
     * @param list<AdviceDescriptor> $afterReturnings
     * @param list<AdviceDescriptor> $afterThrowings
     * @param list<AdviceDescriptor> $afters
     */
    private function wrapWithBeforeAfter(
        string $innerExpr,
        array $befores,
        array $afterReturnings,
        array $afterThrowings,
        array $afters,
    ): string {
        if ($befores === [] && $afterReturnings === [] && $afterThrowings === [] && $afters === []) {
            return $innerExpr;
        }

        $lines = [];
        $lines[] = '(function ($m, $c) {';

        foreach ($befores as $advice) {
            $beforeArgs = ['$m', '$c'];
            if ($advice->runtimeConfig !== []) {
                $beforeArgs[] = $this->exportValue($advice->runtimeConfig);
            }
            $lines[] = sprintf(
                '    $this->locator->get(%s)->%s(%s);',
                $this->exportString($advice->serviceId),
                $advice->method,
                implode(', ', $beforeArgs),
            );
        }

        $needsCatch   = $afterThrowings !== [];
        $needsFinally = $afters !== [];

        if (!$needsCatch && !$needsFinally) {
            if ($afterReturnings === []) {
                $lines[] = '    return ' . $innerExpr . ';';
            } else {
                $lines[] = '    $__result = ' . $innerExpr . ';';
                foreach ($afterReturnings as $advice) {
                    $lines[] = $this->buildAfterReturningCall('    ', $advice, '$m', '$c', '$__result');
                }
                $lines[] = '    return $__result;';
            }
        } else {
            $lines[] = '    try {';
            $lines[] = '        $__result = ' . $innerExpr . ';';
            foreach ($afterReturnings as $advice) {
                $lines[] = $this->buildAfterReturningCall('        ', $advice, '$m', '$c', '$__result');
            }
            $lines[] = '        return $__result;';
            if ($needsCatch) {
                $lines[] = '    } catch (\Throwable $__e) {';
                foreach ($afterThrowings as $advice) {
                    $lines[] = $this->buildAfterThrowingCall('        ', $advice, '$m', '$c', '$__e');
                }
                $lines[] = '        throw $__e;';
            }
            if ($needsFinally) {
                $lines[] = '    } finally {';
                foreach ($afters as $advice) {
                    $lines[] = $this->buildAfterCall('        ', $advice, '$m', '$c');
                }
            }
            $lines[] = '    }';
        }

        $lines[] = '})($m, $c)';

        return implode("\n", $lines);
    }

    private function buildAfterReturningCall(
        string $indent,
        AdviceDescriptor $advice,
        string $messageVar,
        string $contextVar,
        string $resultVar,
    ): string {
        $args = [$messageVar, $contextVar, $resultVar];
        if ($advice->runtimeConfig !== []) {
            $args[] = $this->exportValue($advice->runtimeConfig);
        }

        return sprintf(
            '%s$this->locator->get(%s)->%s(%s);',
            $indent,
            $this->exportString($advice->serviceId),
            $advice->method,
            implode(', ', $args),
        );
    }

    private function buildAfterThrowingCall(
        string $indent,
        AdviceDescriptor $advice,
        string $messageVar,
        string $contextVar,
        string $errorVar,
    ): string {
        $args = [$messageVar, $contextVar, $errorVar];
        if ($advice->runtimeConfig !== []) {
            $args[] = $this->exportValue($advice->runtimeConfig);
        }

        return sprintf(
            '%s$this->locator->get(%s)->%s(%s);',
            $indent,
            $this->exportString($advice->serviceId),
            $advice->method,
            implode(', ', $args),
        );
    }

    private function buildAfterCall(
        string $indent,
        AdviceDescriptor $advice,
        string $messageVar,
        string $contextVar,
    ): string {
        $args = [$messageVar, $contextVar];
        if ($advice->runtimeConfig !== []) {
            $args[] = $this->exportValue($advice->runtimeConfig);
        }

        return sprintf(
            '%s$this->locator->get(%s)->%s(%s);',
            $indent,
            $this->exportString($advice->serviceId),
            $advice->method,
            implode(', ', $args),
        );
    }

    private function exportString(string $value): string
    {
        return "'" . str_replace('\\', '\\\\', $value) . "'";
    }

    /**
     * Export a scalar or array value as a PHP literal string for code generation.
     * Supports string, int, float, bool, null, and recursively nested arrays.
     */
    private function exportValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $this->exportString($value),
            is_int($value)    => (string) $value,
            is_float($value)  => var_export($value, true),
            is_bool($value)   => $value ? 'true' : 'false',
            is_null($value)   => 'null',
            $value === []     => '[]',
            is_array($value)  => $this->exportArray($value),
            default           => throw new \LogicException(
                'Unsupported type in runtimeConfig: ' . get_debug_type($value),
            ),
        };
    }

    private function exportArray(array $array): string
    {
        $isList = array_is_list($array);
        $pairs  = [];
        foreach ($array as $k => $v) {
            $pairs[] = ($isList ? '' : $this->exportValue($k) . ' => ') . $this->exportValue($v);
        }

        return '[' . implode(', ', $pairs) . ']';
    }
}
