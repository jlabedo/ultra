<?php

namespace Ultra\Mapper\CodeGenerator;

use Symfony\Component\TypeInfo\Type;

class ValueObjectPropertyCodeGenerator implements CodeGenerator
{
    private string $valueProperty;
    private string $className;

    public function __construct(string $className, string $valueProperty, private CodeGenerator $next)
    {
        $this->className = $className;
        $this->valueProperty = $valueProperty;
    }

    public function generateNormalization(string $objectVar): string
    {
        return $this->next->generateNormalization("{$objectVar}->{$this->valueProperty}");
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder): string
    {
        $value = $this->next->generateDenormalization($dataVar, $bodyBuilder);

        return "new \\{$this->className}({$value})";
    }

    public function getExpectedDenormalizedType(): Type
    {
        return $this->next->getExpectedDenormalizedType();
    }
}
