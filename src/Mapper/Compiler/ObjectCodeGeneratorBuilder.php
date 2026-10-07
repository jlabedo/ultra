<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;

class ObjectCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    private const VISITED = 'visited';

    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        return (
            $type instanceof ObjectType
            && ($context->isRoot || $context->inline)
            && !self::isVisited($context, $type->getClassName())
        );
    }

    public function build(
        Type $type,
        CodeGeneratorBuilderRegistry $registry,
        CodeGeneratorContext $context,
    ): CodeGenerator {
        if (!$type instanceof ObjectType) {
            throw new \InvalidArgumentException('Type must be ObjectType');
        }
        $context = self::addVisitedClass($context, $type->getClassName());
        if ($context->isRoot) {
            $context = $context->with(isRoot: false);
        }
        $className = $type->getClassName();
        $ref = new \ReflectionClass($className);
        // For each public stored property, create an ObjectPropertyCodeGenerator. Virtual (hooked) properties are
        // derived from stored ones, so they are neither written nor read.
        $properties = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        $propertyGenerators = [];
        foreach ($properties as $property) {
            if ($property->isVirtual()) {
                continue;
            }
            $propertyGenerators[$property->getName()] = new ObjectPropertyCodeGenerator(
                $property->getName(),
                $registry->getCodeGenerator($property, $context),
                $context->typeCheck,
            );
        }

        return new ObjectCodeGenerator($propertyGenerators, $ref);
    }

    private static function isVisited(CodeGeneratorContext $context, string $className): bool
    {
        /** @var array<class-string, true> $visitedClasses */
        $visitedClasses = $context->getAttribute(self::class, self::VISITED) ?? [];

        return isset($visitedClasses[$className]);
    }

    private static function addVisitedClass(CodeGeneratorContext $context, string $className): CodeGeneratorContext
    {
        /** @var array<class-string, true> $visitedClasses */
        $visitedClasses = $context->getAttribute(self::class, self::VISITED) ?? [];

        return $context->withAttribute(self::class, self::VISITED, [...$visitedClasses, $className => true]);
    }
}
