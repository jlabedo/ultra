<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Handler;

use Ultra\CodeGeneration\PhpCode;
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
        $body = $this->indent(2).'$m = $message;'."\n"
            .$this->indent(2).'$c = $context;';

        foreach ($plan->claimedVarNames as $varName) {
            $body = PhpCode::join($body, $this->indent(2).$varName.' = null;');
        }

        $handlerExpr = $this->renderHandlerCall(
            $plan->serviceId,
            $plan->handlerMethod,
            $plan->depServiceIds,
            $plan->claimedVarNames,
            '$m',
        );
        $body = PhpCode::join($body, $this->renderExecutionCode(
            $plan->layers,
            0,
            $handlerExpr,
            $plan->claimedVarNames,
            $plan->returnsVoid,
            2,
            $wrapResults ? '$__result' : null,
            $wrapResults,
        ));

        if ($wrapResults) {
            $body = PhpCode::join($body, $this->indent(2).'return $__result->value;');
        }

        return $body;
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
     */
    private function renderExecutionCode(
        array $layers,
        int $layerIndex,
        string $handlerExpr,
        array $claimedVarNames,
        bool $returnsVoid,
        int $indentLevel,
        ?string $resultVar = null,
        bool $wrapResults = true,
    ): string {
        if ($layerIndex >= count($layers)) {
            return $this->renderTerminalExecutionCode($handlerExpr, $returnsVoid, $indentLevel, $resultVar, $wrapResults);
        }

        $layer = $layers[$layerIndex];
        $code = '';

        foreach ($layer->befores as $beforeIndex => $advice) {
            $args = ['$m', '$c'];
            if ([] !== $advice->runtimeConfig) {
                $args[] = $this->exportValue($advice->runtimeConfig);
            }
            $code = PhpCode::join($code, $this->renderAdviceCall(
                $indentLevel,
                $advice,
                $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args),
                '$__context'.$layerIndex.'_before'.$beforeIndex,
            ));
        }

        $needsAfterReturning = [] !== $layer->afterReturnings;
        $needsCatch = [] !== $layer->afterThrowings;
        $needsFinally = [] !== $layer->afters;
        $hasArounds = [] !== $layer->arounds;

        if (!$needsAfterReturning && !$needsCatch && !$needsFinally) {
            if (!$hasArounds) {
                return PhpCode::join(
                    $code,
                    $this->renderExecutionCode(
                        $layers,
                        $layerIndex + 1,
                        $handlerExpr,
                        $claimedVarNames,
                        $returnsVoid,
                        $indentLevel,
                        $resultVar,
                        $wrapResults,
                    ),
                );
            }

            $aroundExpr = $this->renderAroundStackExpression(
                $layer->arounds,
                $this->renderExecutionCode(
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
            $code = PhpCode::join($code, $this->renderExpression($indentLevel, $prefix, $aroundExpr, ';'));
            if (null !== $resultVar) {
                $code = PhpCode::join($code, $this->indent($indentLevel).'$c = '.$resultVar.'->context;');
            }

            return $code;
        }

        $resultTmp = '$__result'.$layerIndex;
        $errorTmp = '$__e'.$layerIndex;
        $syncResultContextAfterReturning = $wrapResults && $this->hasContextPatchReturningAdvice($layer->afterReturnings);
        $syncResultContextAfterFinally = $wrapResults && $this->hasContextPatchReturningAdvice($layer->afters);

        if (!$needsCatch && !$needsFinally) {
            if ($hasArounds) {
                $aroundExpr = $this->renderAroundStackExpression(
                    $layer->arounds,
                    $this->renderExecutionCode(
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
                $code = PhpCode::join($code, $this->renderExpression($indentLevel, $resultTmp.' = ', $aroundExpr, ';'));
                if ($wrapResults) {
                    $code = PhpCode::join($code, $this->indent($indentLevel).'$c = '.$resultTmp.'->context;');
                }
            } else {
                $code = PhpCode::join(
                    $code,
                    $this->renderExecutionCode(
                        $layers,
                        $layerIndex + 1,
                        $handlerExpr,
                        $claimedVarNames,
                        $returnsVoid,
                        $indentLevel,
                        $resultTmp,
                        $wrapResults,
                    ),
                );
            }

            foreach ($layer->afterReturnings as $afterReturningIndex => $advice) {
                $resultValueExpr = $wrapResults ? $resultTmp.'->value' : $resultTmp;
                $code = PhpCode::join($code, $this->renderAdviceCall(
                    $indentLevel,
                    $advice,
                    $this->renderAfterReturningCallExpression($advice, '$m', '$c', $resultValueExpr),
                    '$__context'.$layerIndex.'_afterReturning'.$afterReturningIndex,
                ));
            }
            if ($syncResultContextAfterReturning) {
                $code = PhpCode::join($code, $this->indent($indentLevel).$resultTmp.' = '.$resultTmp.'->withContext($c);');
            }

            $code = PhpCode::join($code, $this->indent($indentLevel).(null === $resultVar ? 'return '.$resultTmp.';' : $resultVar.' = '.$resultTmp.';'));

            return $code;
        }

        $code = PhpCode::join($code, $this->indent($indentLevel).'try {');

        if ($hasArounds) {
            $aroundExpr = $this->renderAroundStackExpression(
                $layer->arounds,
                $this->renderExecutionCode(
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
            $code = PhpCode::join($code, $this->renderExpression($indentLevel + 1, $resultTmp.' = ', $aroundExpr, ';'));
            if ($wrapResults) {
                $code = PhpCode::join($code, $this->indent($indentLevel + 1).'$c = '.$resultTmp.'->context;');
            }
        } else {
            $code = PhpCode::join(
                $code,
                $this->renderExecutionCode(
                    $layers,
                    $layerIndex + 1,
                    $handlerExpr,
                    $claimedVarNames,
                    $returnsVoid,
                    $indentLevel + 1,
                    $resultTmp,
                    $wrapResults,
                ),
            );
        }

        foreach ($layer->afterReturnings as $afterReturningIndex => $advice) {
            $resultValueExpr = $wrapResults ? $resultTmp.'->value' : $resultTmp;
            $code = PhpCode::join($code, $this->renderAdviceCall(
                $indentLevel + 1,
                $advice,
                $this->renderAfterReturningCallExpression($advice, '$m', '$c', $resultValueExpr),
                '$__context'.$layerIndex.'_afterReturning'.$afterReturningIndex,
            ));
        }
        if ($syncResultContextAfterReturning) {
            $code = PhpCode::join($code, $this->indent($indentLevel + 1).$resultTmp.' = '.$resultTmp.'->withContext($c);');
        }

        if ($needsCatch) {
            $code = PhpCode::join($code, $this->indent($indentLevel).'} catch (\Throwable '.$errorTmp.') {');
            foreach ($layer->afterThrowings as $afterThrowingIndex => $advice) {
                $code = PhpCode::join($code, $this->renderAdviceCall(
                    $indentLevel + 1,
                    $advice,
                    $this->renderAfterThrowingCallExpression($advice, '$m', '$c', $errorTmp),
                    '$__context'.$layerIndex.'_afterThrowing'.$afterThrowingIndex,
                ));
            }
            $code = PhpCode::join($code, $this->indent($indentLevel + 1).'throw '.$errorTmp.';');
        }

        if ($needsFinally) {
            $code = PhpCode::join($code, $this->indent($indentLevel).'} finally {');
            foreach ($layer->afters as $afterIndex => $advice) {
                $code = PhpCode::join($code, $this->renderAdviceCall(
                    $indentLevel + 1,
                    $advice,
                    $this->renderAfterCallExpression($advice, '$m', '$c'),
                    '$__context'.$layerIndex.'_after'.$afterIndex,
                ));
            }
            if ($syncResultContextAfterFinally) {
                $code = PhpCode::join($code, PhpCode::indent(<<<PHP
                    if (isset({$resultTmp})) {
                        {$resultTmp} = {$resultTmp}->withContext(\$c);
                    }
                    PHP, $indentLevel + 1));
            }
        }

        $code = PhpCode::join($code, $this->indent($indentLevel).'}');
        $code = PhpCode::join($code, $this->indent($indentLevel).(null === $resultVar ? 'return '.$resultTmp.';' : $resultVar.' = '.$resultTmp.';'));

        return $code;
    }

    /**
     * @param list<AdviceDescriptor> $arounds
     * @param array<int, string> $claimedVarNames
     */
    private function renderAroundStackExpression(array $arounds, string $innerCode, array $claimedVarNames): string
    {
        $wrappedExpr = '';
        $wrappedCode = $innerCode;

        // Reverse so stable ordering keeps the first advice as the outermost one.
        foreach (array_reverse($arounds) as $advice) {
            $args = [
                $this->renderProceedCallable($advice, $wrappedCode, $claimedVarNames),
                '$m',
                '$c',
            ];
            if ([] !== $advice->runtimeConfig) {
                $args[] = $this->exportValue($advice->runtimeConfig);
            }

            $wrappedExpr = $this->renderServiceMethodCall($advice->serviceId, $advice->method, $args);
            $wrappedCode = $this->renderExpression(1, 'return ', $wrappedExpr, ';');
        }

        return $wrappedExpr;
    }

    /**
     * @param array<int, string> $claimedVarNames
     */
    private function renderProceedCallable(AdviceDescriptor $advice, string $innerCode, array $claimedVarNames): string
    {
        $owner = $advice->className.'::'.$advice->method;

        $captures = ['$m', '$c'];
        foreach ($claimedVarNames as $varName) {
            $captures[] = $varName;
        }
        $captures = array_values(array_unique($captures));

        $captures[1] = '&$c';
        $capturesCode = implode(', ', $captures);
        $expectedProvidedCount = count($advice->claimedDepIndices);
        $proceedErrorMessage = $this->exportString($owner.' must call $proceed() with exactly '.$expectedProvidedCount.' provided value(s); received %d.');

        $body = PhpCode::indent(<<<PHP
            if (count(\$provided) !== {$expectedProvidedCount}) {
                throw new \LogicException(sprintf({$proceedErrorMessage}, count(\$provided)));
            }
            \$c = \$context ?? \$c;
            PHP, 1);

        foreach ($advice->claimedDepIndices as $offset => $depIdx) {
            $body = PhpCode::join($body, $this->indent(1).'$_p'.$depIdx.' = $provided['.$offset.'];');
        }

        $body = PhpCode::join($body, $innerCode);

        return <<<PHP
            function (?\\Ultra\\MessageHandling\\MessageContext \$context = null, array \$provided = []) use ({$capturesCode}) : \\Ultra\\MessageHandling\\ProceedResult {
            {$body}
            }
            PHP;
    }

    /**
     */
    private function renderTerminalExecutionCode(
        string $handlerExpr,
        bool $returnsVoid,
        int $indentLevel,
        ?string $resultVar = null,
        bool $wrapResults = true,
    ): string {
        if (!$wrapResults) {
            if (!$returnsVoid) {
                $prefix = null === $resultVar ? 'return ' : $resultVar.' = ';
                return $this->renderExpression($indentLevel, $prefix, $handlerExpr, ';');
            }

            return $this->indent($indentLevel).$handlerExpr.';'."\n"
                .$this->indent($indentLevel).(null === $resultVar ? 'return null;' : $resultVar.' = null;');
        }

        if (!$returnsVoid) {
            $prefix = null === $resultVar ? 'return new \Ultra\MessageHandling\ProceedResult(' : $resultVar.' = new \Ultra\MessageHandling\ProceedResult(';
            return $this->renderExpression($indentLevel, $prefix, $handlerExpr.', $c)', ';');
        }

        return $this->indent($indentLevel).$handlerExpr.';'."\n"
            .$this->indent($indentLevel).(null === $resultVar
            ? 'return new \Ultra\MessageHandling\ProceedResult(null, $c);'
            : $resultVar.' = new \Ultra\MessageHandling\ProceedResult(null, $c);');
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

    private function renderAdviceCall(
        int $indentLevel,
        AdviceDescriptor $advice,
        string $expression,
        string $contextTmpVar,
    ): string {
        if (!$advice->returnsContextPatch) {
            return $this->indent($indentLevel).$expression.';';
        }

        return PhpCode::indent(<<<PHP
            {$contextTmpVar} = {$expression};
            if (null !== {$contextTmpVar}) {
                \$c = \$c->merge({$contextTmpVar});
            }
            PHP, $indentLevel);
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

    private function renderExpression(int $indentLevel, string $prefix, string $expr, string $suffix = ''): string
    {
        return $this->indent($indentLevel)
            .$prefix
            .PhpCode::indentContinuationLines($expr, $indentLevel + 1)
            .$suffix;
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
