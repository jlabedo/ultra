<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\Path;

class ObjectCodeGenerator implements CodeGenerator
{
    /**
     * @param array<string, ObjectPropertyCodeGenerator> $properties
     */
    public function __construct(private array $properties, private \ReflectionClass $refClass) {}

    public function generateNormalization(string $objectVar): string
    {
        $lines = [];
        foreach ($this->properties as $name => $generator) {
            $lines[] = <<<PHP
                    '{$name}' => {$generator->generateNormalization($objectVar)}
                PHP;
        }

        return "[\n".implode(",\n", $lines)."\n]";
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
    {
        $constructor = $this->refClass->getConstructor();

        $hasNonPromotedProperties = false;
        foreach ($this->properties as $name => $generator) {
            if (! $this->refClass->getProperty($name)->isPromoted()) {
                $hasNonPromotedProperties = true;

                break;
            }
        }

        $internalDataVar = $hasNonPromotedProperties ? '$value' : $dataVar;

        $code = "new \\{$this->refClass->getName()}(";
        $constructorArgs = [];
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                if (isset($this->properties[$name])) {
                    $constructorArgs[$name] = $this->properties[$name]->generateDenormalization($internalDataVar, $bodyBuilder, $path);
                } elseif ($param->allowsNull()) {
                    $constructorArgs[] = 'null';
                } else {
                    throw new \RuntimeException('Constructor missing prop');
                }
            }
        }
        if ($constructorArgs) {
            $code .= "\n".implode(",\n", $constructorArgs)."\n)";
        } else {
            $code .= ')';
        }

        if ($hasNonPromotedProperties) {
            $code = "\$obj = {$code};\n";

            foreach ($this->properties as $name => $generator) {
                if (isset($constructorArgs[$name])) {
                    continue;
                }

                $code .= "\$obj->{$name} = ".$generator->generateDenormalization($internalDataVar, $bodyBuilder, $path).";\n";
            }

            return <<<PHP
                (static function (
                    {$internalDataVar}, 
                    \$type, 
                    \$normalizer, 
                    \$context,
                ) {
                {$code}
                return \$obj;
                })({$dataVar}, \$type, \$normalizer, \$context)
                PHP;
        }

        return $code;

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
