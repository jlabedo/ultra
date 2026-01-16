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
    public function generate(string $targetClass, string $proxyClass, array $methods): string
    {
        $reflection = new \ReflectionClass($targetClass);
        $class = new ClassGenerator($proxyClass);
        $class->setExtendedClass($targetClass);

        $class->addProperty('interceptors', [], PropertyGenerator::FLAG_PRIVATE);

        $constructor = $reflection->getConstructor();
        if ($constructor) {
            $params = [];
            $paramNames = [];
            foreach ($constructor->getParameters() as $parameter) {
                $params[] = [
                    'name' => $parameter->getName(),
                    'type' => $parameter->getType()?->getName(),
                ];
                $paramNames[] = '$' . $parameter->getName();
            }
            
            $class->addMethod(
                '__construct',
                array_merge([['name' => 'interceptors', 'type' => 'array']], $params),
                MethodGenerator::FLAG_PUBLIC,
                '$this->interceptors = $interceptors;' . PHP_EOL . 'parent::__construct(' . implode(', ', $paramNames) . ');'
            );
        } else {
            $class->addMethod(
                '__construct',
                [['name' => 'interceptors', 'type' => 'array']],
                MethodGenerator::FLAG_PUBLIC,
                '$this->interceptors = $interceptors;'
            );
        }

        foreach ($methods as $methodName => $advices) {
            $targetMethod = $reflection->getMethod($methodName);
            $params = [];
            $paramNames = [];
            foreach ($targetMethod->getParameters() as $parameter) {
                $params[] = [
                    'name' => $parameter->getName(),
                    'type' => $parameter->getType()?->getName(),
                ];
                $paramNames[] = '$' . $parameter->getName();
            }

            $methodBody = $this->generateMethodBody($methodName, $advices, $paramNames);
            $class->addMethod(
                $methodName,
                $params,
                MethodGenerator::FLAG_PUBLIC,
                $methodBody
            );
        }

        $file = new FileGenerator();
        $file->setClass($class);
        return $file->generate();
    }

    private function generateMethodBody(string $methodName, array $advices, array $paramNames): string
    {
        $body = "";
        
        $beforeAdvices = $advices['before'] ?? [];
        foreach ($beforeAdvices as $index => $advice) {
            $body .= "\$this->interceptors['{$methodName}']['before'][{$index}]->{$advice['method']}();\n";
        }

        $parentCall = "parent::{$methodName}(" . implode(', ', $paramNames) . ")";

        if (empty($advices['around'])) {
            if (!empty($advices['afterThrowing']) || !empty($advices['afterReturning']) || !empty($advices['after'])) {
                $body .= "try {\n";
                $body .= "    \$returnValue = " . $parentCall . ";\n";
                foreach ($advices['afterReturning'] ?? [] as $index => $advice) {
                    $body .= "    \$this->interceptors['{$methodName}']['afterReturning'][{$index}]->{$advice['method']}(\$returnValue);\n";
                }
                $body .= "} catch (\\Throwable \$e) {\n";
                foreach ($advices['afterThrowing'] ?? [] as $index => $advice) {
                    $body .= "    \$this->interceptors['{$methodName}']['afterThrowing'][{$index}]->{$advice['method']}(\$e);\n";
                }
                $body .= "    throw \$e;\n";
                $body .= "} finally {\n";
                foreach ($advices['after'] ?? [] as $index => $advice) {
                    $body .= "    \$this->interceptors['{$methodName}']['after'][{$index}]->{$advice['method']}();\n";
                }
                $body .= "}\n";
                $body .= "return \$returnValue;\n";
            } else {
                $body .= "return " . $parentCall . ";\n";
            }
        } else {
            $body .= "\$pipeline = function (" . implode(', ', $paramNames) . ") {\n";
            $body .= "    return " . $parentCall . ";\n";
            $body .= "};\n";

            foreach (array_reverse($advices['around']) as $index => $advice) {
                $body .= "\$next = \$pipeline;\n";
                $body .= "\$pipeline = function (" . implode(', ', $paramNames) . ") use (\$next) {\n";
                $body .= "    return \$this->interceptors['{$methodName}']['around'][{$index}]->{$advice['method']}(\$next, " . implode(', ', $paramNames) . ");\n";
                $body .= "};\n";
            }

            $body .= "return \$pipeline(" . implode(', ', $paramNames) . ");\n";
        }

        return $body;
    }
}
