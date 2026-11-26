<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\Path;

class ObjectCodeGenerator implements CodeGenerator
{
    /**
     * @param array<string, ObjectPropertyCodeGenerator> $properties
     */
    public function __construct(
        private array $properties,
        private \ReflectionClass $refClass,
    ) {}

    public function generateNormalization(string $objectVar): string
    {
        $lines = [];
        foreach ($this->properties as $name => $generator) {
            $lines[] = <<<PHP
                    '{$name}' => {$generator->generateNormalization($objectVar)}
                PHP;
        }

        return "[\n" . implode(",\n", $lines) . "\n]";
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
    {
        $constructor = $this->refClass->getConstructor();

        $hasNonPromotedProperties = false;
        foreach ($this->properties as $name => $generator) {
            if ($this->refClass->getProperty($name)->isPromoted()) {
                continue;
            }

            $hasNonPromotedProperties = true;

            break;
        }

        $code = "new \\{$this->refClass->getName()}(";
        /** @var array<array-key, string> $constructorArgs */
        $constructorArgs = [];
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                if (isset($this->properties[$name])) {
                    $constructorArgs[$name] = $this->properties[$name]->generateDenormalization(
                        $dataVar,
                        $bodyBuilder,
                        $path,
                    );
                } elseif ($param->allowsNull()) {
                    $constructorArgs[] = 'null';
                } else {
                    throw new \RuntimeException('Constructor missing prop');
                }
            }
        }
        $code .= $constructorArgs ? "\n" . implode(",\n", $constructorArgs) . "\n)" : ')';

        if (!$hasNonPromotedProperties) {
            return $code;
        }

        $objectVar = $bodyBuilder->newVariable();
        $assignments = [];
        foreach ($this->properties as $name => $generator) {
            if (isset($constructorArgs[$name])) {
                continue;
            }

            $value = $generator->generateDenormalization($dataVar, $bodyBuilder, $path);
            $assignments[] = "{$objectVar}->{$name} = {$value}";
        }
        $bodyBuilder->addStatement("{$objectVar} = {$code}");
        foreach ($assignments as $assignment) {
            $bodyBuilder->addStatement($assignment);
        }

        return $objectVar;
    }

    public function getExpectedDenormalizedType(): Type
    {
        $shape = [];
        foreach ($this->properties as $name => $generator) {
            $shape[$name] = $generator->getExpectedDenormalizedType();
        }

        return Type::arrayShape($shape);
    }
}
