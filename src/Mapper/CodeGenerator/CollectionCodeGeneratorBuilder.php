<?php

namespace Ultra\Mapper\CodeGenerator;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Ultra\Mapper\CodeGeneratorBuilderRegistry;

class CollectionCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        if (! $type instanceof CollectionType) {
            return false;
        }
        $valueType = $type->getCollectionValueType();

        return ! $valueType instanceof BuiltinType;
    }

    public function build(Type $type, CodeGeneratorBuilderRegistry $registry, CodeGeneratorContext $context): CodeGenerator
    {
        if (! $type instanceof CollectionType) {
            throw new \InvalidArgumentException('Type must be CollectionType');
        }
        $valueType = $type->getCollectionValueType();
        $next = $registry->getCodeGenerator($valueType, $context);

        return new CollectionCodeGenerator($next);
    }
}
