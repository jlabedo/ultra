<?php

namespace Ultra\Mapper\CodeGenerator;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\CodeGeneratorBuilderRegistry;

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
