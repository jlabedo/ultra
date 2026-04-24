<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;

class ObjectCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    private const visited = 'visited';

    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        return $type instanceof ObjectType
            && ($context->isRoot || $context->inline)
            && !self::isVisited($context, $type->getClassName());
    }

    public function build(Type $type, CodeGeneratorBuilderRegistry $registry, CodeGeneratorContext $context): CodeGenerator
    {
        if (!$type instanceof ObjectType) {
            throw new \InvalidArgumentException('Type must be ObjectType');
        }
        $context = self::addVisitedClass($context, $type->getClassName());
        if ($context->isRoot) {
            $context = $context->with(isRoot: false);
        }
        $className = $type->getClassName();
        $ref = new \ReflectionClass($className);
        // For each public property, create an ObjectPropertyCodeGenerator
        $properties = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        $propertyGenerators = [];
        foreach ($properties as $property) {
            $propertyGenerators[$property->getName()] = new ObjectPropertyCodeGenerator($property->getName(), $registry->getCodeGenerator($property, $context), $context->typeCheck);
        }

        return new ObjectCodeGenerator($propertyGenerators, $ref);
    }

    private static function isVisited(CodeGeneratorContext $context, string $className): bool
    {
        $visitedClasses = $context->getAttribute(self::class, self::visited) ?? [];

        return isset($visitedClasses[$className]);
    }

    private static function addVisitedClass(CodeGeneratorContext $context, string $className): CodeGeneratorContext
    {
        $visitedClasses = $context->getAttribute(self::class, self::visited) ?? [];

        return $context->withAttribute(self::class, self::visited, [...$visitedClasses, $className => true]);
    }
}
