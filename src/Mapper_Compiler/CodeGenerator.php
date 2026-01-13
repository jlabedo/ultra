<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\Path;

interface CodeGenerator
{
    public function generateNormalization(string $objectVar): string;

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string;

    /**
     * Returns the expected denormalized Symfony TypeInfo Type for this code generator.
     */
    public function getExpectedDenormalizedType(): Type;
}
