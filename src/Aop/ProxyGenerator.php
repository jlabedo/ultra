<?php

declare(strict_types=1);

namespace Ultra\Aop;

use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\FileGenerator;
use Laminas\Code\Generator\MethodGenerator;
use Laminas\Code\Generator\PropertyGenerator;

class ProxyGenerator
{
    /**
     * @param string $targetClass
     * @param array<string, array{before: array, after: array, around: array}> $methods
     * @return string
     */
    public function generate(string $targetClass, string $interceptorClass, array $methods): string
    {
        $reflection = new \ReflectionClass($targetClass);
        $class = new ClassGenerator($interceptorClass);

        $class->addProperty('advices', [], PropertyGenerator::FLAG_PRIVATE);
        $class->addProperty('methods', [], PropertyGenerator::FLAG_PRIVATE);

        $class->addMethod(
            '__construct',
            [['name' => 'advices', 'type' => 'array']],
            MethodGenerator::FLAG_PUBLIC,
            '$this->advices = $advices;' . PHP_EOL . '$this->methods = [];'
        );

        // Generate individual call methods for each intercepted method
        foreach ($methods as $methodName => $advices) {
            $targetMethod = $reflection->getMethod($methodName);
            $methodBody = $this->generateCallMethodBody($methodName, $advices);

            $class->addMethod(
                'call_' . $methodName,
                [
                    ['name' => 'target', 'type' => 'object'],
                    ['name' => 'args', 'type' => 'array']
                ],
                MethodGenerator::FLAG_PUBLIC,
                $methodBody
            );
        }

        // Generate generic invoke method
        $invokeBody = $this->generateInvokeMethod($methods);
        $class->addMethod(
            'invoke',
            [
                ['name' => 'target', 'type' => 'object'],
                ['name' => 'method', 'type' => 'string'],
                ['name' => 'args', 'type' => 'array']
            ],
            MethodGenerator::FLAG_PUBLIC,
            $invokeBody
        );

        $file = new FileGenerator();
        $file->setClass($class);
        return $file->generate();
    }

    private function generateCallMethodBody(string $methodName, array $advices): string
    {
        $body = "";

        $beforeAdvices = $advices['before'] ?? [];
        foreach ($beforeAdvices as $index => $advice) {
            $body .= "\$this->advices['{$methodName}']['before'][{$index}]->{$advice['method']}();\n";
        }

        $targetCall = "\$target->{$methodName}(...\$args)";

        // Build the core execution (target call or around pipeline)
        $coreExecution = "";
        if (!empty($advices['around'])) {
            $coreExecution .= "\$pipeline = function () use (\$target, \$args) {\n";
            $coreExecution .= "    return " . $targetCall . ";\n";
            $coreExecution .= "};\n";

            foreach (array_reverse($advices['around']) as $index => $advice) {
                $coreExecution .= "\$next = \$pipeline;\n";
                $coreExecution .= "\$pipeline = function () use (\$next, \$args) {\n";
                $coreExecution .= "    return \$this->advices['{$methodName}']['around'][{$index}]->{$advice['method']}(\$next, ...\$args);\n";
                $coreExecution .= "};\n";
            }
            $coreExecution .= "\$returnValue = \$pipeline();\n";
        } else {
            $coreExecution .= "\$returnValue = " . $targetCall . ";\n";
        }

        // Wrap with try-catch-finally if needed
        if (!empty($advices['afterThrowing']) || !empty($advices['afterReturning']) || !empty($advices['after'])) {
            $body .= "try {\n";
            $body .= $this->indent($coreExecution, 1);
            foreach ($advices['afterReturning'] ?? [] as $index => $advice) {
                $body .= "    \$this->advices['{$methodName}']['afterReturning'][{$index}]->{$advice['method']}(\$returnValue);\n";
            }
            $body .= "} catch (\\Throwable \$e) {\n";
            foreach ($advices['afterThrowing'] ?? [] as $index => $advice) {
                $body .= "    \$this->advices['{$methodName}']['afterThrowing'][{$index}]->{$advice['method']}(\$e);\n";
            }
            $body .= "    throw \$e;\n";
            $body .= "} finally {\n";
            foreach ($advices['after'] ?? [] as $index => $advice) {
                $body .= "    \$this->advices['{$methodName}']['after'][{$index}]->{$advice['method']}();\n";
            }
            $body .= "}\n";
            $body .= "return \$returnValue;\n";
        } else {
            $body .= $coreExecution;
            $body .= "return \$returnValue;\n";
        }

        return $body;
    }

    private function indent(string $code, int $levels): string
    {
        $indent = str_repeat('    ', $levels);
        return $indent . str_replace("\n", "\n" . $indent, rtrim($code));
    }

    private function generateInvokeMethod(array $methods): string
    {
        $body = "return match (\$method) {\n";
        foreach (array_keys($methods) as $methodName) {
            $body .= "    '{$methodName}' => \$this->call_{$methodName}(\$target, \$args),\n";
        }
        $body .= "    default => \$target->\$method(...\$args),\n";
        $body .= "};\n";

        return $body;
    }
}
