<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

use Ultra\CodeGeneration\PhpCode;
use Ultra\CodeGeneration\PhpSnippet;
use Ultra\MessageHandling_Compiler\HandlerDependencyExpressionResolverInterface;

final class DispatchPlanRenderer
{
    /**
     * @param list<HandlerDependencyExpressionResolverInterface> $dependencyResolvers
     */
    public function __construct(
        private readonly array $dependencyResolvers = [],
    ) {}

    public function renderDispatchMethod(string $dispatchName, DispatchPlan $plan): string
    {
        $body = $this->renderBody($plan);

        return <<<PHP
                private function {$dispatchName}(object \$message, \\Ultra\\MessageHandling\\MessageContext \$context): mixed
                {
            {$body}
                }
            PHP;
    }

    public function renderBody(DispatchPlan $plan): string
    {
        $wrapResults = $this->planHasArounds($plan);
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
                $wrapResults ? '$__result' : null,
                $wrapResults,
            ),
        ];
        if ($wrapResults) {
            $bodyLines[] = $this->indent(2).'return $__result->value;';
        }

        return implode("\n", $bodyLines);
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
        $isSelf = HandlerDescriptor::SELF_SERVICE_ID === $serviceId;

        $args = [];
        if (!$isSelf) {
            $args[] = $messageVar;
        }

        foreach ($depServiceIds as $depIdx => $depId) {
            $args[] = $claimedVarNames[$depIdx]
                ?? $this->renderHandlerDependencyExpression($depId);
        }

        return $isSelf
            ? sprintf('%s->%s(%s)', $messageVar, $handlerMethod, implode(', ', $args))
            : $this->renderServiceMethodCall($serviceId, $handlerMethod, $args);
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
     * @param list<AdviceLayer>  $layers
     * @param array<int, string> $claimedVarNames
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
        bool $wrapResults = true,
    ): array {
        if ($layerIndex >= count($layers)) {
            return $this->renderTerminalExecutionLines($handlerExpr, $returnsVoid, $indentLevel, $resultVar, $wrapResults);
        }

        $layer = $layers[$layerIndex];
        $lines = [];

        foreach ($layer->befores as $beforeIndex => $advice) {
            $args = ['$m', '$c'];
            if ([] !== $advice->runtimeConfig) {
                $args[] = $this->exportValue($advice->runtimeConfig);
            }
            $this->appendAdviceCall(
                $lines,
                $indentLevel,
                $advice,
                $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args),
                '$__context'.$layerIndex.'_before'.$beforeIndex,
            );
        }

        $needsAfterReturning = [] !== $layer->afterReturnings;
        $needsCatch = [] !== $layer->afterThrowings;
        $needsFinally = [] !== $layer->afters;
        $hasArounds = [] !== $layer->arounds;

        if (!$needsAfterReturning && !$needsCatch && !$needsFinally) {
            if (!$hasArounds) {
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
                        $wrapResults,
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
                    null,
                    true,
                ),
                $claimedVarNames,
            );

            $prefix = null === $resultVar ? 'return ' : $resultVar.' = ';
            $this->appendExpression($lines, $indentLevel, $prefix, $aroundExpr, ';');
            if (null !== $resultVar) {
                $lines[] = $this->indent($indentLevel).'$c = '.$resultVar.'->context;';
            }

            return $lines;
        }

        $resultTmp = '$__result'.$layerIndex;
        $errorTmp = '$__e'.$layerIndex;
        $syncResultContextAfterReturning = $wrapResults && $this->hasContextPatchReturningAdvice($layer->afterReturnings);
        $syncResultContextAfterFinally = $wrapResults && $this->hasContextPatchReturningAdvice($layer->afters);

        if (!$needsCatch && !$needsFinally) {
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
                        null,
                        true,
                    ),
                    $claimedVarNames,
                );
                $this->appendExpression($lines, $indentLevel, $resultTmp.' = ', $aroundExpr, ';');
                if ($wrapResults) {
                    $lines[] = $this->indent($indentLevel).'$c = '.$resultTmp.'->context;';
                }
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
                        $wrapResults,
                    ),
                ];
            }

            foreach ($layer->afterReturnings as $afterReturningIndex => $advice) {
                $resultValueExpr = $wrapResults ? $resultTmp.'->value' : $resultTmp;
                $this->appendAdviceCall(
                    $lines,
                    $indentLevel,
                    $advice,
                    $this->renderAfterReturningCallExpression($advice, '$m', '$c', $resultValueExpr),
                    '$__context'.$layerIndex.'_afterReturning'.$afterReturningIndex,
                );
            }
            if ($syncResultContextAfterReturning) {
                $lines[] = $this->indent($indentLevel).$resultTmp.' = '.$resultTmp.'->withContext($c);';
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
                    null,
                    true,
                ),
                $claimedVarNames,
            );
            $this->appendExpression($lines, $indentLevel + 1, $resultTmp.' = ', $aroundExpr, ';');
            if ($wrapResults) {
                $lines[] = $this->indent($indentLevel + 1).'$c = '.$resultTmp.'->context;';
            }
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
                    $wrapResults,
                ),
            ];
        }

        foreach ($layer->afterReturnings as $afterReturningIndex => $advice) {
            $resultValueExpr = $wrapResults ? $resultTmp.'->value' : $resultTmp;
            $this->appendAdviceCall(
                $lines,
                $indentLevel + 1,
                $advice,
                $this->renderAfterReturningCallExpression($advice, '$m', '$c', $resultValueExpr),
                '$__context'.$layerIndex.'_afterReturning'.$afterReturningIndex,
            );
        }
        if ($syncResultContextAfterReturning) {
            $lines[] = $this->indent($indentLevel + 1).$resultTmp.' = '.$resultTmp.'->withContext($c);';
        }

        if ($needsCatch) {
            $lines[] = $this->indent($indentLevel).'} catch (\Throwable '.$errorTmp.') {';
            foreach ($layer->afterThrowings as $afterThrowingIndex => $advice) {
                $this->appendAdviceCall(
                    $lines,
                    $indentLevel + 1,
                    $advice,
                    $this->renderAfterThrowingCallExpression($advice, '$m', '$c', $errorTmp),
                    '$__context'.$layerIndex.'_afterThrowing'.$afterThrowingIndex,
                );
            }
            $lines[] = $this->indent($indentLevel + 1).'throw '.$errorTmp.';';
        }

        if ($needsFinally) {
            $lines[] = $this->indent($indentLevel).'} finally {';
            foreach ($layer->afters as $afterIndex => $advice) {
                $this->appendAdviceCall(
                    $lines,
                    $indentLevel + 1,
                    $advice,
                    $this->renderAfterCallExpression($advice, '$m', '$c'),
                    '$__context'.$layerIndex.'_after'.$afterIndex,
                );
            }
            if ($syncResultContextAfterFinally) {
                $lines[] = $this->indent($indentLevel + 1).'if (isset('.$resultTmp.')) {';
                $lines[] = $this->indent($indentLevel + 2).$resultTmp.' = '.$resultTmp.'->withContext($c);';
                $lines[] = $this->indent($indentLevel + 1).'}';
            }
        }

        $lines[] = $this->indent($indentLevel).'}';
        $lines[] = $this->indent($indentLevel).(null === $resultVar ? 'return '.$resultTmp.';' : $resultVar.' = '.$resultTmp.';');

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

        $captures[1] = '&$c';

        $lines = ['function (?\Ultra\MessageHandling\MessageContext $context = null, array $provided = []) use ('.implode(', ', $captures).') : \Ultra\MessageHandling\ProceedResult {'];
        $lines[] = $this->indent(1).'if (count($provided) !== '.count($advice->claimedDepIndices).') {';
        $lines[] = $this->indent(2).'throw new \LogicException(sprintf('
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
        bool $wrapResults = true,
    ): array {
        if (!$wrapResults) {
            if (!$returnsVoid) {
                $lines = [];
                $prefix = null === $resultVar ? 'return ' : $resultVar.' = ';
                $this->appendExpression($lines, $indentLevel, $prefix, $handlerExpr, ';');

                return $lines;
            }

            $lines = [$this->indent($indentLevel).$handlerExpr.';'];
            $lines[] = $this->indent($indentLevel).(null === $resultVar ? 'return null;' : $resultVar.' = null;');

            return $lines;
        }

        if (!$returnsVoid) {
            $lines = [];
            $prefix = null === $resultVar ? 'return new \Ultra\MessageHandling\ProceedResult(' : $resultVar.' = new \Ultra\MessageHandling\ProceedResult(';
            $this->appendExpression($lines, $indentLevel, $prefix, $handlerExpr.', $c)', ';');

            return $lines;
        }

        $lines = [$this->indent($indentLevel).$handlerExpr.';'];
        $lines[] = $this->indent($indentLevel).(null === $resultVar
            ? 'return new \Ultra\MessageHandling\ProceedResult(null, $c);'
            : $resultVar.' = new \Ultra\MessageHandling\ProceedResult(null, $c);');

        return $lines;
    }

    private function planHasArounds(DispatchPlan $plan): bool
    {
        foreach ($plan->layers as $layer) {
            if ([] !== $layer->arounds) {
                return true;
            }
        }

        return false;
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

    private function renderAfterReturningCallExpression(
        AdviceDescriptor $advice,
        string $messageVar,
        string $contextVar,
        string $resultValueExpr,
    ): string {
        $args = [$messageVar, $contextVar, $resultValueExpr];
        if ([] !== $advice->runtimeConfig) {
            $args[] = $this->exportValue($advice->runtimeConfig);
        }

        return $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args);
    }

    private function renderAfterThrowingCallExpression(
        AdviceDescriptor $advice,
        string $messageVar,
        string $contextVar,
        string $errorVar,
    ): string {
        $args = [$messageVar, $contextVar, $errorVar];
        if ([] !== $advice->runtimeConfig) {
            $args[] = $this->exportValue($advice->runtimeConfig);
        }

        return $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args);
    }

    private function renderAfterCallExpression(
        AdviceDescriptor $advice,
        string $messageVar,
        string $contextVar,
    ): string {
        $args = [$messageVar, $contextVar];
        if ([] !== $advice->runtimeConfig) {
            $args[] = $this->exportValue($advice->runtimeConfig);
        }

        return $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args);
    }

    /**
     * @param list<string> $lines
     */
    private function appendAdviceCall(
        array &$lines,
        int $indentLevel,
        AdviceDescriptor $advice,
        string $expression,
        string $contextTmpVar,
    ): void {
        if (!$advice->returnsContextPatch) {
            $lines[] = $this->indent($indentLevel).$expression.';';

            return;
        }

        $lines[] = $this->indent($indentLevel).$contextTmpVar.' = '.$expression.';';
        $lines[] = $this->indent($indentLevel).'if (null !== '.$contextTmpVar.') {';
        $lines[] = $this->indent($indentLevel + 1).'$c = $c->merge('.$contextTmpVar.');';
        $lines[] = $this->indent($indentLevel).'}';
    }

    /**
     * @param list<AdviceDescriptor> $advices
     */
    private function hasContextPatchReturningAdvice(array $advices): bool
    {
        foreach ($advices as $advice) {
            if ($advice->returnsContextPatch) {
                return true;
            }
        }

        return false;
    }

    private function indent(int $level): string
    {
        return PhpCode::indentLevel($level);
    }

    /**
     * @param list<string> $lines
     */
    private function appendExpression(array &$lines, int $indentLevel, string $prefix, string $expr, string $suffix = ''): void
    {
        $rendered = $this->indent($indentLevel)
            .$prefix
            .PhpSnippet::raw($expr)->indentContinuationLines($indentLevel + 1)
            .$suffix;
        $lines = [...$lines, ...explode("\n", $rendered)];
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
