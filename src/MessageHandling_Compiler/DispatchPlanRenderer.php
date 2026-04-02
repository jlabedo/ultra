<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler;

final class DispatchPlanRenderer
{
    private const INDENT = '    ';

    /**
     * @param list<HandlerDependencyExpressionResolverInterface> $dependencyResolvers
     */
    public function __construct(
        private readonly array $dependencyResolvers = [],
    ) {}

    public function renderDispatchMethod(string $dispatchName, DispatchPlan $plan): string
    {
        $bodyLines = [
            $this->indent(2).'$m = $message;',
            $this->indent(2).'$c = $context;',
        ];
        foreach ($plan->claimedVarNames as $varName) {
            $bodyLines[] = $this->indent(2).$varName.' = null;';
        }
        $handlerExpr = $this->renderHandlerCall(
            $plan->serviceId,
            $plan->handlerMethod,
            $plan->depServiceIds,
            $plan->claimedVarNames,
            '$m',
        );
        $bodyLines = [
            ...$bodyLines,
            ...$this->renderExecutionLines(
                $plan->layers,
                0,
                $handlerExpr,
                $plan->claimedVarNames,
                $plan->returnsVoid,
                2,
            ),
        ];

        $body = implode("\n", $bodyLines);

        return <<<PHP
                private function {$dispatchName}(object \$message, \\Ultra\\MessageHandling\\MessageContext \$context): mixed
                {
            {$body}
                }
            PHP;
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
    ): string {
        $isSelf = '@self' === $serviceId;

        $args = [];
        if (! $isSelf) {
            $args[] = $messageVar;
        }

        foreach ($depServiceIds as $depIdx => $depId) {
            $args[] = $claimedVarNames[$depIdx]
                ?? $this->renderHandlerDependencyExpression($depId);
        }

        $call = $isSelf
            ? sprintf('%s->%s(%s)', $messageVar, $handlerMethod, implode(', ', $args))
            : $this->renderServiceMethodCall($serviceId, $handlerMethod, $args);

        return $call;
    }

    private function renderHandlerDependencyExpression(string $depId): string
    {
        foreach ($this->dependencyResolvers as $resolver) {
            $expression = $resolver->resolve($depId, '$c');
            if (null !== $expression) {
                return $expression;
            }
        }

        return '$this->locator->get('.$this->exportString($depId).')';
    }

    /**
     * @param list<AdviceLayer>      $layers
     * @param array<int, string>     $claimedVarNames
     *
     * @return list<string>
     */
    private function renderExecutionLines(
        array $layers,
        int $layerIndex,
        string $handlerExpr,
        array $claimedVarNames,
        bool $returnsVoid,
        int $indentLevel,
        ?string $resultVar = null,
    ): array {
        if ($layerIndex >= count($layers)) {
            return $this->renderTerminalExecutionLines($handlerExpr, $returnsVoid, $indentLevel, $resultVar);
        }

        $layer = $layers[$layerIndex];
        $lines = [];

        foreach ($layer->befores as $advice) {
            $args = ['$m', '$c'];
            if ([] !== $advice->runtimeConfig) {
                $args[] = $this->exportValue($advice->runtimeConfig);
            }
            $lines[] = $this->indent($indentLevel).$this->renderServiceMethodCall($advice->serviceId, $advice->method, $args).';';
        }

        $needsAfterReturning = [] !== $layer->afterReturnings;
        $needsCatch = [] !== $layer->afterThrowings;
        $needsFinally = [] !== $layer->afters;
        $hasArounds = [] !== $layer->arounds;

        if (! $needsAfterReturning && ! $needsCatch && ! $needsFinally) {
            if (! $hasArounds) {
                return [
                    ...$lines,
                    ...$this->renderExecutionLines(
                        $layers,
                        $layerIndex + 1,
                        $handlerExpr,
                        $claimedVarNames,
                        $returnsVoid,
                        $indentLevel,
                        $resultVar,
                    ),
                ];
            }

            $aroundExpr = $this->renderAroundStackExpression(
                $layer->arounds,
                $this->renderExecutionLines(
                    $layers,
                    $layerIndex + 1,
                    $handlerExpr,
                    $claimedVarNames,
                    $returnsVoid,
                    1,
                ),
                $claimedVarNames,
            );

            $prefix = null === $resultVar ? 'return ' : $resultVar.' = ';
            $this->appendExpression($lines, $indentLevel, $prefix, $aroundExpr, ';');

            return $lines;
        }

        $resultTmp = '$__result'.$layerIndex;
        $errorTmp = '$__e'.$layerIndex;

        if (! $needsCatch && ! $needsFinally) {
            if ($hasArounds) {
                $aroundExpr = $this->renderAroundStackExpression(
                    $layer->arounds,
                    $this->renderExecutionLines(
                        $layers,
                        $layerIndex + 1,
                        $handlerExpr,
                        $claimedVarNames,
                        $returnsVoid,
                        1,
                    ),
                    $claimedVarNames,
                );
                $this->appendExpression($lines, $indentLevel, $resultTmp.' = ', $aroundExpr, ';');
            } else {
                $lines = [
                    ...$lines,
                    ...$this->renderExecutionLines(
                        $layers,
                        $layerIndex + 1,
                        $handlerExpr,
                        $claimedVarNames,
                        $returnsVoid,
                        $indentLevel,
                        $resultTmp,
                    ),
                ];
            }

            foreach ($layer->afterReturnings as $advice) {
                $lines[] = $this->indent($indentLevel).$this->renderAfterReturningCall($advice, '$m', '$c', $resultTmp);
            }

            $lines[] = $this->indent($indentLevel).(null === $resultVar ? 'return '.$resultTmp.';' : $resultVar.' = '.$resultTmp.';');

            return $lines;
        }

        $lines[] = $this->indent($indentLevel).'try {';

        if ($hasArounds) {
            $aroundExpr = $this->renderAroundStackExpression(
                $layer->arounds,
                $this->renderExecutionLines(
                    $layers,
                    $layerIndex + 1,
                    $handlerExpr,
                    $claimedVarNames,
                    $returnsVoid,
                    1,
                ),
                $claimedVarNames,
            );
            $this->appendExpression($lines, $indentLevel + 1, $resultTmp.' = ', $aroundExpr, ';');
        } else {
            $lines = [
                ...$lines,
                ...$this->renderExecutionLines(
                    $layers,
                    $layerIndex + 1,
                    $handlerExpr,
                    $claimedVarNames,
                    $returnsVoid,
                    $indentLevel + 1,
                    $resultTmp,
                ),
            ];
        }

        foreach ($layer->afterReturnings as $advice) {
            $lines[] = $this->indent($indentLevel + 1).$this->renderAfterReturningCall($advice, '$m', '$c', $resultTmp);
        }

        $lines[] = $this->indent($indentLevel + 1).(null === $resultVar ? 'return '.$resultTmp.';' : $resultVar.' = '.$resultTmp.';');

        if ($needsCatch) {
            $lines[] = $this->indent($indentLevel).'} catch (\Throwable '.$errorTmp.') {';
            foreach ($layer->afterThrowings as $advice) {
                $lines[] = $this->indent($indentLevel + 1).$this->renderAfterThrowingCall($advice, '$m', '$c', $errorTmp);
            }
            $lines[] = $this->indent($indentLevel + 1).'throw '.$errorTmp.';';
        }

        if ($needsFinally) {
            $lines[] = $this->indent($indentLevel).'} finally {';
            foreach ($layer->afters as $advice) {
                $lines[] = $this->indent($indentLevel + 1).$this->renderAfterCall($advice, '$m', '$c');
            }
        }

        $lines[] = $this->indent($indentLevel).'}';

        return $lines;
    }

    /**
     * @param list<AdviceDescriptor> $arounds
     * @param array<int, string>     $claimedVarNames
     */
    private function renderAroundStackExpression(array $arounds, array $innerLines, array $claimedVarNames): string
    {
        $wrappedExpr = '';
        $wrappedLines = $innerLines;

        // Reverse so stable ordering keeps the first advice as the outermost one.
        foreach (array_reverse($arounds) as $advice) {
            $args = [
                $this->renderProceedCallable($advice, $wrappedLines, $claimedVarNames),
                '$m',
                '$c',
            ];
            if ([] !== $advice->runtimeConfig) {
                $args[] = $this->exportValue($advice->runtimeConfig);
            }

            $wrappedExpr = $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args);
            $wrappedLines = [];
            $this->appendExpression($wrappedLines, 1, 'return ', $wrappedExpr, ';');
        }

        return $wrappedExpr;
    }

    /**
     * @param array<int, string> $claimedVarNames
     * @param list<string>       $innerLines
     */
    private function renderProceedCallable(AdviceDescriptor $advice, array $innerLines, array $claimedVarNames): string
    {
        $owner = $advice->className.'::'.$advice->method;

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

        $lines = [...$lines, ...$innerLines];
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function renderTerminalExecutionLines(
        string $handlerExpr,
        bool $returnsVoid,
        int $indentLevel,
        ?string $resultVar = null,
    ): array {
        if (! $returnsVoid) {
            $lines = [];
            $prefix = null === $resultVar ? 'return ' : $resultVar.' = ';
            $this->appendExpression($lines, $indentLevel, $prefix, $handlerExpr, ';');

            return $lines;
        }

        $lines = [$this->indent($indentLevel).$handlerExpr.';'];
        $lines[] = $this->indent($indentLevel).(null === $resultVar ? 'return null;' : $resultVar.' = null;');

        return $lines;
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
