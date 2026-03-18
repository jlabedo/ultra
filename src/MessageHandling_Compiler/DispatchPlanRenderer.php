<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

final class DispatchPlanRenderer
{
    private const INDENT = '    ';

    public function renderDispatchMethod(string $dispatchName, DispatchPlan $plan): string
    {
        $dispatchExpr = $this->renderDispatchExpression($plan);

        $bodyLines = [
            $this->indent(2).'$m = $message;',
            $this->indent(2).'$c = $context;',
        ];
        foreach ($plan->claimedVarNames as $varName) {
            $bodyLines[] = $this->indent(2).$varName.' = null;';
        }
        $this->appendExpression($bodyLines, 2, 'return ', $dispatchExpr, ';');

        $body = implode("\n", $bodyLines);

        return <<<PHP
                private function {$dispatchName}(object \$message, \\Ultra\\MessageHandling\\MessageContext \$context): mixed
                {
            {$body}
                }
            PHP;
    }

    private function renderDispatchExpression(DispatchPlan $plan): string
    {
        $expr = $this->renderHandlerCall(
            $plan->serviceId,
            $plan->handlerMethod,
            $plan->depServiceIds,
            $plan->claimedVarNames,
            '$m',
            $plan->returnsVoid,
        );

        foreach (array_reverse($plan->layers) as $layer) {
            $expr = $this->renderAroundStack($layer->arounds, $expr, $plan->claimedVarNames);
            $expr = $this->renderLayerFrame(
                $expr,
                $layer->befores,
                $layer->afterReturnings,
                $layer->afterThrowings,
                $layer->afters,
            );
        }

        return $expr;
    }

    /**
     * @param list<string>       $depServiceIds
     * @param array<int, string> $claimedVarNames
     */
    private function renderHandlerCall(
        string $serviceId,
        string $handlerMethod,
        array $depServiceIds,
        array $claimedVarNames,
        string $messageVar,
        bool $returnsVoid = false,
    ): string {
        $isSelf = '@self' === $serviceId;

        $args = [];
        if (! $isSelf) {
            $args[] = $messageVar;
        }

        foreach ($depServiceIds as $depIdx => $depId) {
            $args[] = $claimedVarNames[$depIdx]
                ?? '$this->locator->get('.$this->exportString($depId).')';
        }

        $call = $isSelf
            ? sprintf('%s->%s(%s)', $messageVar, $handlerMethod, implode(', ', $args))
            : $this->renderServiceMethodCall($serviceId, $handlerMethod, $args);

        if (! $returnsVoid) {
            return $call;
        }

        $captures = [$messageVar];
        foreach ($claimedVarNames as $varName) {
            $captures[] = $varName;
        }
        $captures = array_values(array_unique($captures));

        return sprintf(
            '(function () use (%s) { %s; return null; })()',
            implode(', ', $captures),
            $call,
        );
    }

    /**
     * @param list<AdviceDescriptor> $arounds
     * @param array<int, string>     $claimedVarNames
     */
    private function renderAroundStack(array $arounds, string $innerExpr, array $claimedVarNames): string
    {
        if ([] === $arounds) {
            return $innerExpr;
        }

        $wrapped = $innerExpr;

        // Reverse so stable ordering keeps the first advice as the outermost one.
        foreach (array_reverse($arounds) as $advice) {
            $args = [
                $this->renderProceed($advice, $wrapped, $claimedVarNames),
                '$m',
                '$c',
            ];
            if ([] !== $advice->runtimeConfig) {
                $args[] = $this->exportValue($advice->runtimeConfig);
            }

            $wrapped = $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args);
        }

        return $wrapped;
    }

    /**
     * @param list<AdviceDescriptor> $befores
     * @param list<AdviceDescriptor> $afterReturnings
     * @param list<AdviceDescriptor> $afterThrowings
     * @param list<AdviceDescriptor> $afters
     */
    private function renderLayerFrame(
        string $innerExpr,
        array $befores,
        array $afterReturnings,
        array $afterThrowings,
        array $afters,
    ): string {
        if ([] === $befores && [] === $afterReturnings && [] === $afterThrowings && [] === $afters) {
            return $innerExpr;
        }

        $lines = ['(function ($m, $c) {'];

        foreach ($befores as $advice) {
            $args = ['$m', '$c'];
            if ([] !== $advice->runtimeConfig) {
                $args[] = $this->exportValue($advice->runtimeConfig);
            }
            $lines[] = $this->indent(1).$this->renderServiceMethodCall($advice->serviceId, $advice->method, $args).';';
        }

        $needsCatch = [] !== $afterThrowings;
        $needsFinally = [] !== $afters;

        if (! $needsCatch && ! $needsFinally) {
            if ([] === $afterReturnings) {
                $this->appendExpression($lines, 1, 'return ', $innerExpr, ';');
            } else {
                $this->appendExpression($lines, 1, '$__result = ', $innerExpr, ';');
                foreach ($afterReturnings as $advice) {
                    $lines[] = $this->indent(1).$this->renderAfterReturningCall($advice, '$m', '$c', '$__result');
                }
                $lines[] = $this->indent(1).'return $__result;';
            }
        } else {
            $lines[] = $this->indent(1).'try {';
            $this->appendExpression($lines, 2, '$__result = ', $innerExpr, ';');

            foreach ($afterReturnings as $advice) {
                $lines[] = $this->indent(2).$this->renderAfterReturningCall($advice, '$m', '$c', '$__result');
            }

            $lines[] = $this->indent(2).'return $__result;';

            if ($needsCatch) {
                $lines[] = $this->indent(1).'} catch (\Throwable $__e) {';
                foreach ($afterThrowings as $advice) {
                    $lines[] = $this->indent(2).$this->renderAfterThrowingCall($advice, '$m', '$c', '$__e');
                }
                $lines[] = $this->indent(2).'throw $__e;';
            }

            if ($needsFinally) {
                $lines[] = $this->indent(1).'} finally {';
                foreach ($afters as $advice) {
                    $lines[] = $this->indent(2).$this->renderAfterCall($advice, '$m', '$c');
                }
            }

            $lines[] = $this->indent(1).'}';
        }

        $lines[] = '})($m, $c)';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $claimedVarNames
     */
    private function renderProceed(AdviceDescriptor $advice, string $innerExpr, array $claimedVarNames): string
    {
        return $this->renderProceedCallable($advice, $innerExpr, $claimedVarNames);
    }

    /**
     * @param array<int, string> $claimedVarNames
     */
    private function renderProceedCallable(AdviceDescriptor $advice, string $innerExpr, array $claimedVarNames): string
    {
        $owner = '' !== $advice->className
            ? $advice->className.'::'.$advice->method
            : $advice->serviceId.'::'.$advice->method;

        $captures = ['$m', '$c'];
        foreach ($claimedVarNames as $varName) {
            $captures[] = $varName;
        }
        $captures = array_values(array_unique($captures));

        $lines = ['function (?\\Ultra\\MessageHandling\\MessageContext $context = null, array $provided = []) use ('.implode(', ', $captures).') {'];
        $lines[] = $this->indent(1).'if (count($provided) !== '.count($advice->claimedDepIndices).') {';
        $lines[] = $this->indent(2).'throw new \\LogicException(sprintf('
            .$this->exportString($owner.' must call $proceed() with exactly '.count($advice->claimedDepIndices).' provided value(s); received %d.')
            .', count($provided)));';
        $lines[] = $this->indent(1).'}';
        $lines[] = $this->indent(1).'$c = $context ?? $c;';

        foreach ($advice->claimedDepIndices as $offset => $depIdx) {
            $lines[] = $this->indent(1).'$_p'.$depIdx.' = $provided['.$offset.'];';
        }

        $this->appendExpression($lines, 1, 'return ', $innerExpr, ';');
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $args
     */
    private function renderServiceMethodCall(string $serviceId, string $method, array $args): string
    {
        return sprintf(
            '$this->locator->get(%s)->%s(%s)',
            $this->exportString($serviceId),
            $method,
            implode(', ', $args),
        );
    }

    private function renderAfterReturningCall(
        AdviceDescriptor $advice,
        string $messageVar,
        string $contextVar,
        string $resultVar,
    ): string {
        $args = [$messageVar, $contextVar, $resultVar];
        if ([] !== $advice->runtimeConfig) {
            $args[] = $this->exportValue($advice->runtimeConfig);
        }

        return $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args).';';
    }

    private function renderAfterThrowingCall(
        AdviceDescriptor $advice,
        string $messageVar,
        string $contextVar,
        string $errorVar,
    ): string {
        $args = [$messageVar, $contextVar, $errorVar];
        if ([] !== $advice->runtimeConfig) {
            $args[] = $this->exportValue($advice->runtimeConfig);
        }

        return $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args).';';
    }

    private function renderAfterCall(
        AdviceDescriptor $advice,
        string $messageVar,
        string $contextVar,
    ): string {
        $args = [$messageVar, $contextVar];
        if ([] !== $advice->runtimeConfig) {
            $args[] = $this->exportValue($advice->runtimeConfig);
        }

        return $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args).';';
    }

    private function indent(int $level): string
    {
        return str_repeat(self::INDENT, $level);
    }

    /**
     * @param list<string> $lines
     */
    private function appendExpression(array &$lines, int $indentLevel, string $prefix, string $expr, string $suffix = ''): void
    {
        $lines = [...$lines, ...explode("\n", $this->inlineExpression($this->indent($indentLevel).$prefix, $expr, $suffix))];
    }

    private function inlineExpression(string $prefix, string $expr, string $suffix = ''): string
    {
        $exprLines = explode("\n", $expr);
        if (1 === count($exprLines)) {
            return $prefix.$expr.$suffix;
        }

        preg_match('/^\s*/', $prefix, $matches);
        $continuationIndent = ($matches[0] ?? '').self::INDENT;

        $lines = [$prefix.$exprLines[0]];
        foreach (array_slice($exprLines, 1) as $line) {
            $lines[] = $continuationIndent.$line;
        }

        $lines[count($lines) - 1] .= $suffix;

        return implode("\n", $lines);
    }

    private function exportString(string $value): string
    {
        return "'".str_replace('\\', '\\\\', $value)."'";
    }

    /**
     * Export a scalar or array value as a PHP literal string for code generation.
     * Supports string, int, float, bool, null, and recursively nested arrays.
     */
    private function exportValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $this->exportString($value),
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => 'null',
            [] === $value => '[]',
            is_array($value) => $this->exportArray($value),
            default => throw new \LogicException(
                'Unsupported type in runtimeConfig: '.get_debug_type($value),
            ),
        };
    }

    private function exportArray(array $array): string
    {
        $isList = array_is_list($array);
        $pairs = [];
        foreach ($array as $k => $v) {
            $pairs[] = ($isList ? '' : $this->exportValue($k).' => ').$this->exportValue($v);
        }

        return '['.implode(', ', $pairs).']';
    }
}
