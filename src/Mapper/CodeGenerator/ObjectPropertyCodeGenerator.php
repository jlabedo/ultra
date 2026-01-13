<?php

namespace Ultra\Mapper\CodeGenerator;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\Exception\DenormalizationException;

class ObjectPropertyCodeGenerator implements CodeGenerator
{
    public function __construct(public readonly string $name, private CodeGenerator $next, private CodeGeneratorContext $context) {}

    public function generateNormalization(string $objectVar): string
    {
        return $this->next->generateNormalization("{$objectVar}->{$this->name}");
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder): string
    {
        if ($this->context->typeCheck) {
            $denormalizationException = DenormalizationException::class;
            $bodyBuilder->addStatement(<<<PHP
                    if (! \\array_key_exists('{$this->name}', {$dataVar})) {
                        throw new \\{$denormalizationException}("Missing required property '{$this->name}' at path {{$this->context->path->generateRuntimeContextCall(["'{$this->name}'"])}}");
                    }
                PHP);
        }

        return $this->next->generateDenormalization("{$dataVar}['{$this->name}']", $bodyBuilder);
    }

    public function getExpectedDenormalizedType(): Type
    {
        return $this->next->getExpectedDenormalizedType();
    }
}
