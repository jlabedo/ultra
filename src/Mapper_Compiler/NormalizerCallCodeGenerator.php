<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\Path;

class NormalizerCallCodeGenerator implements CodeGenerator
{
    public function __construct(private string $type) {}

    public function generateNormalization(string $objectVar): string
    {
        return "\$normalizer->toScalar({$objectVar})";
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
    {
        return "\$normalizer->fromScalar({$dataVar}, '{$this->type}', \$context->with(path: {$path->generateRuntimeContextCall()}))";
    }

    public function getExpectedDenormalizedType(): Type
    {
        return Type::array(Type::mixed(), Type::string());
    }
}
