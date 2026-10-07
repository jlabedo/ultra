<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;

readonly class RuntimeNormalizerCallCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    /**
     * @param array<class-string, true> $runtimeNormalizers
     */
    public function __construct(
        private array $runtimeNormalizers,
    ) {}

    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        return $type instanceof ObjectType && isset($this->runtimeNormalizers[$type->getClassName()]);
    }

    public function build(
        Type $type,
        CodeGeneratorBuilderRegistry $registry,
        CodeGeneratorContext $context,
    ): CodeGenerator {
        if (!$type instanceof ObjectType) {
            throw new \InvalidArgumentException('Type must be ObjectType');
        }

        return new NormalizerCallCodeGenerator($type->getClassName());
    }
}
