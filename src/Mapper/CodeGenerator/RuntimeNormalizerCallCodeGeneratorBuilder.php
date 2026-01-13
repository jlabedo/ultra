<?php

namespace Ultra\Mapper\CodeGenerator;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Ultra\Mapper\CodeGeneratorBuilderRegistry;

class RuntimeNormalizerCallCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    public function __construct(private array $runtimeNormalizers) {}

    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        return $type instanceof ObjectType && isset($this->runtimeNormalizers[$type->getClassName()]);
    }

    public function build(Type $type, CodeGeneratorBuilderRegistry $registry, CodeGeneratorContext $context): CodeGenerator
    {
        if (! $type instanceof ObjectType) {
            throw new \InvalidArgumentException('Type must be ObjectType');
        }

        return new NormalizerCallCodeGenerator($type->getClassName());
    }
}
