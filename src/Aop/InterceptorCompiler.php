<?php

declare(strict_types=1);

namespace Ultra\Aop;

class InterceptorCompiler
{
    private const string VERSION_ID = '2';

    public function __construct(
        private readonly ?string $cacheDir = null
    ) {}

    /**
     * Compile an interceptor into optimized PHP code.
     *
     * @return string The fully qualified class name of the compiled interceptor
     */
    public function compile(Interceptor $interceptor): string
    {
        $hash = $interceptor->getHash();
        $className = 'CompiledInterceptor_'.self::VERSION_ID.$hash;

        $cacheDir = $this->cacheDir ?? dirname(__DIR__, 2).'/var/aop_cache';
        $cacheFile = $cacheDir.'/'.$className.'.php';

        // Return cached version if exists
        if (file_exists($cacheFile)) {
            require_once $cacheFile;

            return $className;
        }

        // Generate the code
        $code = $this->generateCode($className, $interceptor);

        // Write to cache
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0o777, true);
        }
        file_put_contents($cacheFile, $code);
        require_once $cacheFile;

        return $className;
    }

    private function generateCode(string $className, Interceptor $interceptor): string
    {
        $metadata = $interceptor->getMetadata();

        $code = "<?php\n\n";
        $code .= "use Psr\\Container\\ContainerInterface;\n\n";
        $code .= "class {$className}\n";
        $code .= "{\n";

        // Add properties for container and object references
        $code .= "    private ContainerInterface \$container;\n";
        
        // Store object references for container-based callables
        $callableMeta = $metadata['callable'];
        if ($callableMeta['type'] === CallableReference::TYPE_CONTAINER) {
            $code .= "    private object \$callableObject;\n";
        }
        
        // Store object references for container-based advices
        $containerAdviceIndices = [];
        foreach (['beforeAdvices', 'afterAdvices', 'afterReturningAdvices', 'afterThrowingAdvices', 'aroundAdvices'] as $adviceType) {
            foreach ($metadata[$adviceType] as $idx => $adviceMeta) {
                if ($adviceMeta['type'] === CallableReference::TYPE_CONTAINER) {
                    $containerAdviceIndices[] = ['type' => $adviceType, 'index' => $idx, 'meta' => $adviceMeta];
                    $code .= "    private object \$adviceObject_{$adviceType}_{$idx};\n";
                }
            }
        }

        $code .= "\n";

        // Constructor
        $code .= "    public function __construct(\n";
        $code .= "        ContainerInterface \$container\n";
        $code .= "    ) {\n";
        $code .= "        \$this->container = \$container;\n";
        
        // Resolve container-based callable
        if ($callableMeta['type'] === CallableReference::TYPE_CONTAINER) {
            $code .= "        \$this->callableObject = \$container->get('{$callableMeta['containerId']}');\n";
        }
        
        // Resolve container-based advices
        foreach ($containerAdviceIndices as $adviceInfo) {
            $type = $adviceInfo['type'];
            $idx = $adviceInfo['index'];
            $meta = $adviceInfo['meta'];
            $code .= "        \$this->adviceObject_{$type}_{$idx} = \$container->get('{$meta['containerId']}');\n";
        }
        
        $code .= "    }\n\n";

        // Generate optimized call method
        $code .= $this->generateCallMethod($metadata);

        // Generate helper method for around chain if needed
        if ($metadata['aroundCount'] > 0) {
            $code .= $this->generateAroundHelperMethod($metadata);
        }

        $code .= "}\n";

        return $code;
    }

    private function generateCallMethod(array $metadata): string
    {
        $hasAround = $metadata['aroundCount'] > 0;
        $hasAfterReturning = $metadata['afterReturningCount'] > 0;
        $hasAfterThrowing = $metadata['afterThrowingCount'] > 0;
        $hasAfter = $metadata['afterCount'] > 0;
        $hasTryCatch = $hasAfterReturning || $hasAfterThrowing || $hasAfter;

        $code = "    public function call(...\$args)\n";
        $code .= "    {\n";

        // Before advices
        if ($metadata['beforeCount'] > 0) {
            $code .= "        // Execute before advices\n";
            for ($i = 0; $i < $metadata['beforeCount']; ++$i) {
                $adviceMeta = $metadata['beforeAdvices'][$i];
                $objectProperty = $adviceMeta['type'] === CallableReference::TYPE_CONTAINER 
                    ? "adviceObject_beforeAdvices_{$i}" 
                    : null;
                $code .= "        " . $this->generateCallCode($adviceMeta, $objectProperty, []) . ";\n";
            }
            $code .= "\n";
        }

        // Execute with or without try-catch
        if ($hasTryCatch) {
            $code .= "        try {\n";
            if ($hasAround) {
                // Use helper method to reduce stack depth in main call method
                $code .= "            \$returnValue = \$this->executeAroundChain(\$args);\n";
            } else {
                $callableMeta = $metadata['callable'];
                $objectProperty = $callableMeta['type'] === CallableReference::TYPE_CONTAINER 
                    ? 'callableObject' 
                    : null;
                $code .= "            \$returnValue = " . $this->generateCallCode($callableMeta, $objectProperty, ['...$args']) . ";\n";
            }

            // After returning
            if ($hasAfterReturning) {
                $code .= "\n";
                for ($i = 0; $i < $metadata['afterReturningCount']; ++$i) {
                    $adviceMeta = $metadata['afterReturningAdvices'][$i];
                    $objectProperty = $adviceMeta['type'] === CallableReference::TYPE_CONTAINER 
                        ? "adviceObject_afterReturningAdvices_{$i}" 
                        : null;
                    $code .= "            " . $this->generateCallCode($adviceMeta, $objectProperty, ['$returnValue']) . ";\n";
                }
            }

            $code .= "        } catch (\\Throwable \$e) {\n";

            // After throwing
            if ($hasAfterThrowing) {
                for ($i = 0; $i < $metadata['afterThrowingCount']; ++$i) {
                    $adviceMeta = $metadata['afterThrowingAdvices'][$i];
                    $objectProperty = $adviceMeta['type'] === CallableReference::TYPE_CONTAINER 
                        ? "adviceObject_afterThrowingAdvices_{$i}" 
                        : null;
                    $code .= "            " . $this->generateCallCode($adviceMeta, $objectProperty, ['$e']) . ";\n";
                }
            }

            $code .= "            throw \$e;\n";
            $code .= '        }';

            // After (finally)
            if ($hasAfter) {
                $code .= " finally {\n";
                for ($i = 0; $i < $metadata['afterCount']; ++$i) {
                    $adviceMeta = $metadata['afterAdvices'][$i];
                    $objectProperty = $adviceMeta['type'] === CallableReference::TYPE_CONTAINER 
                        ? "adviceObject_afterAdvices_{$i}" 
                        : null;
                    $code .= "            " . $this->generateCallCode($adviceMeta, $objectProperty, []) . ";\n";
                }
                $code .= "        }\n";
            } else {
                $code .= "\n";
            }

            $code .= "\n";
            $code .= "        return \$returnValue;\n";
        } else {
            // No try-catch needed
            if ($hasAround) {
                // Use helper method to reduce stack depth in main call method
                $code .= "        return \$this->executeAroundChain(\$args);\n";
            } else {
                $callableMeta = $metadata['callable'];
                $objectProperty = $callableMeta['type'] === CallableReference::TYPE_CONTAINER 
                    ? 'callableObject' 
                    : null;
                $code .= "        return " . $this->generateCallCode($callableMeta, $objectProperty, ['...$args']) . ";\n";
            }
        }

        $code .= "    }\n";

        return $code;
    }

    /**
     * Generate code to call a method based on CallableReference metadata
     */
    private function generateCallCode(array $meta, ?string $objectProperty, array $args): string
    {
        $argsStr = implode(', ', $args);
        
        return match ($meta['type']) {
            CallableReference::TYPE_CONTAINER => "\$this->{$objectProperty}->{$meta['methodName']}(" . $argsStr . ")",
            CallableReference::TYPE_STATIC => "\\{$meta['className']}::{$meta['methodName']}(" . $argsStr . ")",
            CallableReference::TYPE_FUNCTION => "\\{$meta['methodName']}(" . $argsStr . ")",
        };
    }

    /**
     * Generate helper method for around chain execution
     * This reduces stack depth in the main call() method by moving
     * the around chain logic to a separate method.
     * Builds the chain using an array to avoid closure variable capture issues.
     */
    private function generateAroundHelperMethod(array $metadata): string
    {
        $aroundCount = $metadata['aroundCount'];
        $callableMeta = $metadata['callable'];
        
        $code = "\n";
        $code .= "    private function executeAroundChain(array \$args)\n";
        $code .= "    {\n";

        // Build the chain from innermost (callable) to outermost (last around advice)
        // around advices are applied in reverse order, so around[count-1] is outermost
        // Use an array to store closures to avoid variable capture issues

        $code .= "        \$chain = [];\n";
        $code .= "        \$chain[0] = function () use (\$args) {\n";
        $objectProperty = $callableMeta['type'] === CallableReference::TYPE_CONTAINER 
            ? 'callableObject' 
            : null;
        $code .= "            return " . $this->generateCallCode($callableMeta, $objectProperty, ['...$args']) . ";\n";
        $code .= "        };\n\n";

        // Wrap with each around advice from last to first (reverse order)
        for ($i = $aroundCount - 1; $i >= 0; --$i) {
            $prevIndex = $aroundCount - $i - 1;
            $currentIndex = $aroundCount - $i;
            $adviceMeta = $metadata['aroundAdvices'][$i];
            
            $objectProperty = $adviceMeta['type'] === CallableReference::TYPE_CONTAINER 
                ? "adviceObject_aroundAdvices_{$i}" 
                : null;

            $code .= "        \$prev{$currentIndex} = \$chain[{$prevIndex}];\n";
            $code .= "        \$chain[{$currentIndex}] = function () use (\$prev{$currentIndex}, \$args) {\n";
            $code .= "            return " . $this->generateCallCode($adviceMeta, $objectProperty, ['$prev' . $currentIndex, '...$args']) . ";\n";
            $code .= "        };\n";
            if ($i > 0) {
                $code .= "\n";
            }
        }

        $finalIndex = $aroundCount;
        $code .= "\n";
        $code .= "        return \$chain[{$finalIndex}]();\n";
        $code .= "    }\n";

        return $code;
    }
}
