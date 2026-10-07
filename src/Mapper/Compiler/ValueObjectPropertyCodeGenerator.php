<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\Path;

class ValueObjectPropertyCodeGenerator implements CodeGenerator
{
    private string $valueProperty;
    private string $className;

    public function __construct(
        string $className,
        string $valueProperty,
        private CodeGenerator $next,
    ) {
        $this->className = $className;
        $this->valueProperty = $valueProperty;
    }

    public function generateNormalization(string $objectVar): string
    {
        return $this->next->generateNormalization("{$objectVar}->{$this->valueProperty}");
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
    {
        $value = $this->next->generateDenormalization($dataVar, $bodyBuilder, $path);

        return "new \\{$this->className}({$value})";
    }

    public function getExpectedDenormalizedType(): Type
    {
        return $this->next->getExpectedDenormalizedType();
    }
}
