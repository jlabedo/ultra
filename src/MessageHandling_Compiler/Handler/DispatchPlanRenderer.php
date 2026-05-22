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

    public function renderDispatchMethod(string $dispatchName, DispatchPlan $plan): PhpCode
    {
        $body = $this->renderBody($plan);

        return PhpCode::from(<<<PHP
                private function {$dispatchName}(object \$message, \\Ultra\\MessageHandling\\MessageContext \$context): mixed
                {
                {$body->indent(1)}
                }
                PHP);
    }

    public function renderBody(DispatchPlan $plan): PhpCode
    {
        $wrapResults = $this->planHasArounds($plan);

        $claimesdVarsBlock = PhpCode::empty();
        foreach ($plan->claimedVarNames as $varName) {
            $claimesdVarsBlock = $claimesdVarsBlock->add($varName.' = null;');
        }

        $handlerExpr = $this->renderHandlerCall(
            $plan->serviceId,
            $plan->handlerMethod,
            $plan->depServiceIds,
            $plan->claimedVarNames,
            '$m',
        );
        $executionCode = $this->renderExecutionCode(
            $plan->layers,
            0,
            $handlerExpr,
            $plan->claimedVarNames,
            $plan->returnsVoid,
            $wrapResults ? '$__result' : null,
            $wrapResults,
        );

        if ($wrapResults) {
            $executionCode = $executionCode->add('return $__result->value;');
        }

        return PhpCode::from(<<<PHP
            \$m = \$message;
            \$c = \$context;
            {$claimesdVarsBlock}
            {$executionCode}
            PHP
        );
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
        ?string $resultVar = null,
        bool $wrapResults = true,
    ): PhpCode {
        if ($layerIndex >= count($layers)) {
            return $this->renderTerminalExecutionCode($handlerExpr, $returnsVoid, $resultVar, $wrapResults);
        }

        $layer = $layers[$layerIndex];
        $code = PhpCode::empty();

        foreach ($layer->befores as $beforeIndex => $advice) {
            $args = ['$m', '$c'];
            if ([] !== $advice->runtimeConfig) {
                $args[] = $this->exportValue($advice->runtimeConfig);
            }
            $code = PhpCode::join($code, $this->renderAdviceCall(
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
                    null,
                    true,
                ),
                $claimedVarNames,
            );

            $prefix = null === $resultVar ? 'return ' : $resultVar.' = ';
            $code = PhpCode::join($code, $this->renderExpression($prefix, $aroundExpr, ';'));
            if (null !== $resultVar) {
                $code = PhpCode::join($code, '$c = '.$resultVar.'->context;');
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
                        null,
                        true,
                    ),
                    $claimedVarNames,
                );
                $code = PhpCode::join($code, $this->renderExpression($resultTmp.' = ', $aroundExpr, ';'));
                if ($wrapResults) {
                    $code = PhpCode::join($code, '$c = '.$resultTmp.'->context;');
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
                        $resultTmp,
                        $wrapResults,
                    ),
                );
            }

            foreach ($layer->afterReturnings as $afterReturningIndex => $advice) {
                $resultValueExpr = $wrapResults ? $resultTmp.'->value' : $resultTmp;
                $code = PhpCode::join($code, $this->renderAdviceCall(
                    $advice,
                    $this->renderAfterReturningCallExpression($advice, '$m', '$c', $resultValueExpr),
                    '$__context'.$layerIndex.'_afterReturning'.$afterReturningIndex,
                ));
            }
            if ($syncResultContextAfterReturning) {
                $code = PhpCode::join($code, $resultTmp.' = '.$resultTmp.'->withContext($c);');
            }

            $code = PhpCode::join($code, null === $resultVar ? 'return '.$resultTmp.';' : $resultVar.' = '.$resultTmp.';');

            return $code;
        }

        $tryBody = PhpCode::from('');

        if ($hasArounds) {
            $aroundExpr = $this->renderAroundStackExpression(
                $layer->arounds,
                $this->renderExecutionCode(
                    $layers,
                    $layerIndex + 1,
                    $handlerExpr,
                    $claimedVarNames,
                    $returnsVoid,
                    null,
                    true,
                ),
                $claimedVarNames,
            );
            $tryBody = PhpCode::join($tryBody, $this->renderExpression($resultTmp.' = ', $aroundExpr, ';'));
            if ($wrapResults) {
                $tryBody = PhpCode::join($tryBody, '$c = '.$resultTmp.'->context;');
            }
        } else {
            $tryBody = PhpCode::join(
                $tryBody,
                $this->renderExecutionCode(
                    $layers,
                    $layerIndex + 1,
                    $handlerExpr,
                    $claimedVarNames,
                    $returnsVoid,
                    $resultTmp,
                    $wrapResults,
                ),
            );
        }

        foreach ($layer->afterReturnings as $afterReturningIndex => $advice) {
            $resultValueExpr = $wrapResults ? $resultTmp.'->value' : $resultTmp;
            $tryBody = PhpCode::join($tryBody, $this->renderAdviceCall(
                $advice,
                $this->renderAfterReturningCallExpression($advice, '$m', '$c', $resultValueExpr),
                '$__context'.$layerIndex.'_afterReturning'.$afterReturningIndex,
            ));
        }
        if ($syncResultContextAfterReturning) {
            $tryBody = PhpCode::join($tryBody, $resultTmp.' = '.$resultTmp.'->withContext($c);');
        }

        $code = PhpCode::join($code, 'try {', PhpCode::from($tryBody)->indent(1));

        if ($needsCatch) {
            $catchBody = PhpCode::from('');
            foreach ($layer->afterThrowings as $afterThrowingIndex => $advice) {
                $catchBody = PhpCode::join($catchBody, $this->renderAdviceCall(
                    $advice,
                    $this->renderAfterThrowingCallExpression($advice, '$m', '$c', $errorTmp),
                    '$__context'.$layerIndex.'_afterThrowing'.$afterThrowingIndex,
                ));
            }
            $catchBody = PhpCode::join($catchBody, 'throw '.$errorTmp.';');
            $code = PhpCode::join($code, '} catch (\Throwable '.$errorTmp.') {', PhpCode::from($catchBody)->indent(1));
        }

        if ($needsFinally) {
            $finallyBody = PhpCode::from('');
            foreach ($layer->afters as $afterIndex => $advice) {
                $finallyBody = PhpCode::join($finallyBody, $this->renderAdviceCall(
                    $advice,
                    $this->renderAfterCallExpression($advice, '$m', '$c'),
                    '$__context'.$layerIndex.'_after'.$afterIndex,
                ));
            }
            if ($syncResultContextAfterFinally) {
                $finallyBody = PhpCode::join($finallyBody, <<<PHP
                    if (isset({$resultTmp})) {
                        {$resultTmp} = {$resultTmp}->withContext(\$c);
                    }
                    PHP);
            }
            $code = PhpCode::join($code, '} finally {', PhpCode::from($finallyBody)->indent(1));
        }

        $code = PhpCode::join($code, '}');
        $code = PhpCode::join($code, null === $resultVar ? 'return '.$resultTmp.';' : $resultVar.' = '.$resultTmp.';');

        return $code;
    }

    /**
     * @param list<AdviceDescriptor> $arounds
     * @param array<int, string> $claimedVarNames
     */
    private function renderAroundStackExpression(array $arounds, string|PhpCode $innerCode, array $claimedVarNames): string
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
            $wrappedCode = $this->renderExpression('return ', $wrappedExpr, ';');
        }

        return $wrappedExpr;
    }

    /**
     * @param array<int, string> $claimedVarNames
     */
    private function renderProceedCallable(AdviceDescriptor $advice, string|PhpCode $innerCode, array $claimedVarNames): string
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

        $body = <<<PHP
            if (count(\$provided) !== {$expectedProvidedCount}) {
                throw new \LogicException(sprintf({$proceedErrorMessage}, count(\$provided)));
            }
            \$c = \$context ?? \$c;
            PHP;

        foreach ($advice->claimedDepIndices as $offset => $depIdx) {
            $body = PhpCode::join($body, '$_p'.$depIdx.' = $provided['.$offset.'];');
        }

        $body = PhpCode::join($body, $innerCode);
        $bodyCode = PhpCode::from($body)->indent(1);

        return <<<PHP
            function (?\\Ultra\\MessageHandling\\MessageContext \$context = null, array \$provided = []) use ({$capturesCode}) : \\Ultra\\MessageHandling\\ProceedResult {
            {$bodyCode}
            }
            PHP;
    }

    /**
     */
    private function renderTerminalExecutionCode(
        string $handlerExpr,
        bool $returnsVoid,
        ?string $resultVar = null,
        bool $wrapResults = true,
    ): PhpCode {
        if (!$wrapResults) {
            if (!$returnsVoid) {
                $prefix = null === $resultVar ? 'return ' : $resultVar.' = ';
                return $this->renderExpression($prefix, $handlerExpr, ';');
            }

            return PhpCode::from($handlerExpr.';'."\n"
                .(null === $resultVar ? 'return null;' : $resultVar.' = null;'));
        }

        if (!$returnsVoid) {
            $prefix = null === $resultVar ? 'return new \Ultra\MessageHandling\ProceedResult(' : $resultVar.' = new \Ultra\MessageHandling\ProceedResult(';
            return $this->renderExpression($prefix, $handlerExpr.', $c)', ';');
        }

        return PhpCode::from($handlerExpr.';'."\n"
            .(null === $resultVar
            ? 'return new \Ultra\MessageHandling\ProceedResult(null, $c);'
            : $resultVar.' = new \Ultra\MessageHandling\ProceedResult(null, $c);'));
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
        AdviceDescriptor $advice,
        string $expression,
        string $contextTmpVar,
    ): PhpCode {
        if (!$advice->returnsContextPatch) {
            return PhpCode::from($expression.';');
        }

        return PhpCode::from(<<<PHP
            {$contextTmpVar} = {$expression};
            if (null !== {$contextTmpVar}) {
                \$c = \$c->merge({$contextTmpVar});
            }
            PHP);
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

    private function renderExpression(string $prefix, string $expr, string $suffix = ''): PhpCode
    {
        return PhpCode::from($prefix
            .PhpCode::from($expr)->indentContinuationLines(1)
            .$suffix);
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
