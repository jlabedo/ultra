<?php

namespace Ultra\Mapper\CodeGenerator;

use Symfony\Component\TypeInfo\Type;

interface CodeGenerator
{
    public function generateNormalization(string $objectVar): string;

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder): string;

    /**
     * Returns the expected denormalized Symfony TypeInfo Type for this code generator.
     */
    public function getExpectedDenormalizedType(): Type;
}
