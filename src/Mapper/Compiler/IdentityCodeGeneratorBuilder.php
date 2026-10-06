<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;

class IdentityCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        return true; // Fallback builder
    }

    public function build(Type $type, CodeGeneratorBuilderRegistry $registry, CodeGeneratorContext $context): CodeGenerator
    {
        return new IdentityCodeGenerator($type, $context->typeCheck);
    }
}
