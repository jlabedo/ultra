<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;

class NormalizerCallCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        return $type instanceof ObjectType && ! $context->isRoot;
    }

    public function build(Type $type, CodeGeneratorBuilderRegistry $registry, CodeGeneratorContext $context): CodeGenerator
    {
        if (! $type instanceof ObjectType) {
            throw new \InvalidArgumentException('Type must be ObjectType');
        }

        return new NormalizerCallCodeGenerator($type->getClassName());
    }
}
