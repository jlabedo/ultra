<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;

interface CodeGeneratorBuilder
{
    /**
     * Returns true if this builder can handle the given type and context.
     */
    public function supports(Type $type, CodeGeneratorContext $context): bool;

    /**
     * Builds a CodeGenerator for the given type and context.
     */
    public function build(Type $type, CodeGeneratorBuilderRegistry $registry, CodeGeneratorContext $context): CodeGenerator;
}
