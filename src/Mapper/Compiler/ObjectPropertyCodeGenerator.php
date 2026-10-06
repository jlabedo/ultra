<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\Exception\DenormalizationException;
use Ultra\Mapper\Path;

class ObjectPropertyCodeGenerator implements CodeGenerator
{
    public function __construct(public readonly string $name, private CodeGenerator $next, private bool $typeCheck) {}

    public function generateNormalization(string $objectVar): string
    {
        return $this->next->generateNormalization("{$objectVar}->{$this->name}");
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
    {
        if ($this->typeCheck) {
            $denormalizationException = DenormalizationException::class;
            $bodyBuilder->addStatement(<<<PHP
                // ObjectPropertyCodeGenerator for {$this->name} (\$dataVar = {$dataVar}) ({$path})
                if (! \\array_key_exists('{$this->name}', {$dataVar})) {
                    throw new \\{$denormalizationException}("Missing required property '{$this->name}' at path {{$path->generateRuntimeContextCall(["'{$this->name}'"])}}");
                }
                PHP);
        }

        return $this->next->generateDenormalization("{$dataVar}['{$this->name}']", $bodyBuilder, $path->add($this->name));
    }

    public function getExpectedDenormalizedType(): Type
    {
        return $this->next->getExpectedDenormalizedType();
    }
}
