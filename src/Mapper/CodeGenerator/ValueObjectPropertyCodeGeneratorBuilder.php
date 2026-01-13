<?php

namespace Ultra\Mapper\CodeGenerator;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Ultra\Mapper\Attribute\AsValue;
use Ultra\Mapper\CodeGeneratorBuilderRegistry;

class ValueObjectPropertyCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        if (! $type instanceof ObjectType) {
            return false;
        }
        $ref = new \ReflectionClass($type->getClassName());

        return $ref->getAttributes(AsValue::class) && 1 === count($ref->getProperties());
    }

    public function build(Type $type, CodeGeneratorBuilderRegistry $registry, CodeGeneratorContext $context): CodeGenerator
    {
        if (! $type instanceof ObjectType) {
            throw new \InvalidArgumentException('Type must be ObjectType');
        }
        $className = $type->getClassName();
        $ref = new \ReflectionClass($className);
        $props = $ref->getProperties();
        if (1 !== count($props)) {
            throw new \RuntimeException('AsValue classes should have exactly one property');
        }
        $property = $props[0];
        $next = $registry->getCodeGenerator($property, $context);

        return new ValueObjectPropertyCodeGenerator($className, $property->getName(), $next);
    }
}
