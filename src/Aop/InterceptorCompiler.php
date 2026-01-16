<?php

declare(strict_types=1);

namespace Ultra\Aop;

class InterceptorCompiler
{
    public function __construct(
        private readonly ?string $cacheDir = null
    ) {
    }

    /**
     * Compile an interceptor into optimized PHP code
     *
     * @return string The fully qualified class name of the compiled interceptor
     */
    public function compile(Interceptor $interceptor): string
    {
        $hash = $interceptor->getHash();
        $className = 'CompiledInterceptor_' . $hash;

        $cacheDir = $this->cacheDir ?? dirname(__DIR__, 2) . '/var/aop_cache';
        $cacheFile = $cacheDir . '/' . $className . '.php';

        // Return cached version if exists
        if (file_exists($cacheFile)) {
            require_once $cacheFile;
            return $className;
        }

        // Generate the code
        $code = $this->generateCode($className, $interceptor);

        // Write to cache
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        file_put_contents($cacheFile, $code);
        require_once $cacheFile;

        return $className;
    }

    private function generateCode(string $className, Interceptor $interceptor): string
    {
        $metadata = $interceptor->getMetadata();

        $code = "<?php\n\n";
        $code .= "class {$className}\n";
        $code .= "{\n";

        // Add properties for callable and advices
        $code .= "    private \$callable;\n";
        $code .= "    private array \$beforeAdvices = [];\n";
        $code .= "    private array \$afterAdvices = [];\n";
        $code .= "    private array \$afterReturningAdvices = [];\n";
        $code .= "    private array \$afterThrowingAdvices = [];\n";
        $code .= "    private array \$aroundAdvices = [];\n\n";

        // Constructor
        $code .= "    public function __construct(\n";
        $code .= "        \$callable,\n";
        $code .= "        array \$beforeAdvices,\n";
        $code .= "        array \$afterAdvices,\n";
        $code .= "        array \$afterReturningAdvices,\n";
        $code .= "        array \$afterThrowingAdvices,\n";
        $code .= "        array \$aroundAdvices\n";
        $code .= "    ) {\n";
        $code .= "        \$this->callable = \$callable;\n";
        $code .= "        \$this->beforeAdvices = \$beforeAdvices;\n";
        $code .= "        \$this->afterAdvices = \$afterAdvices;\n";
        $code .= "        \$this->afterReturningAdvices = \$afterReturningAdvices;\n";
        $code .= "        \$this->afterThrowingAdvices = \$afterThrowingAdvices;\n";
        $code .= "        \$this->aroundAdvices = \$aroundAdvices;\n";
        $code .= "    }\n\n";

        // Generate optimized call method
        $code .= $this->generateCallMethod($metadata);

        // Generate helper method for around chain if needed
        if ($metadata['aroundCount'] > 0) {
            $code .= $this->generateAroundHelperMethod($metadata['aroundCount']);
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
            for ($i = 0; $i < $metadata['beforeCount']; $i++) {
                $code .= "        (\$this->beforeAdvices[{$i}])();\n";
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
                $code .= "            \$returnValue = (\$this->callable)(...\$args);\n";
            }

            // After returning
            if ($hasAfterReturning) {
                $code .= "\n";
                for ($i = 0; $i < $metadata['afterReturningCount']; $i++) {
                    $code .= "            (\$this->afterReturningAdvices[{$i}])(\$returnValue);\n";
                }
            }

            $code .= "        } catch (\\Throwable \$e) {\n";

            // After throwing
            if ($hasAfterThrowing) {
                for ($i = 0; $i < $metadata['afterThrowingCount']; $i++) {
                    $code .= "            (\$this->afterThrowingAdvices[{$i}])(\$e);\n";
                }
            }

            $code .= "            throw \$e;\n";
            $code .= "        }";

            // After (finally)
            if ($hasAfter) {
                $code .= " finally {\n";
                for ($i = 0; $i < $metadata['afterCount']; $i++) {
                    $code .= "            (\$this->afterAdvices[{$i}])();\n";
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
                $code .= "        return (\$this->callable)(...\$args);\n";
            }
        }

        $code .= "    }\n";

        return $code;
    }

    /**
     * Generate helper method for around chain execution
     * This reduces stack depth in the main call() method by moving
     * the around chain logic to a separate method.
     * Builds the chain using an array to avoid closure variable capture issues.
     */
    private function generateAroundHelperMethod(int $aroundCount): string
    {
        $code = "\n";
        $code .= "    private function executeAroundChain(array \$args)\n";
        $code .= "    {\n";
        
        // Build the chain from innermost (callable) to outermost (last around advice)
        // around advices are applied in reverse order, so around[count-1] is outermost
        // Use an array to store closures to avoid variable capture issues
        
        $code .= "        \$chain = [];\n";
        $code .= "        \$chain[0] = function () use (\$args) {\n";
        $code .= "            return (\$this->callable)(...\$args);\n";
        $code .= "        };\n\n";
        
        // Wrap with each around advice from last to first (reverse order)
        for ($i = $aroundCount - 1; $i >= 0; $i--) {
            $prevIndex = $aroundCount - $i - 1;
            $currentIndex = $aroundCount - $i;
            
            $code .= "        \$prev{$currentIndex} = \$chain[{$prevIndex}];\n";
            $code .= "        \$chain[{$currentIndex}] = function () use (\$prev{$currentIndex}, \$args) {\n";
            $code .= "            return (\$this->aroundAdvices[{$i}])(\$prev{$currentIndex}, ...\$args);\n";
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
