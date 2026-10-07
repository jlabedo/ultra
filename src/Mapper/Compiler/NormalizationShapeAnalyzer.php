<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Ultra\Mapper\Attribute\AsValue;
use Ultra\Mapper\RuntimeNormalizerInterface;

/**
 * @phpstan-type Shape array{kind: 'scalar', types: list<string>}|array{kind: 'object', fields: array<string, Shape>}
 */
final readonly class NormalizationShapeAnalyzer
{
    /**
     * @param array<class-string, class-string<RuntimeNormalizerInterface>> $runtimeNormalizersByType
     */
    public function __construct(
        private array $runtimeNormalizersByType = [],
    ) {}

    /**
     * @param class-string $class
     *
     * @return Shape
     */
    public function forClass(string $class): array
    {
        return $this->forNamedType($class);
    }

    /** @return Shape */
    public function forProperty(\ReflectionProperty $property): array
    {
        $type = $property->getType();
        if (null === $type) {
            throw new \LogicException(sprintf(
                'Property %s::$%s must declare a type to expose a normalization shape.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        return $this->forReflectionType($type, sprintf(
            '%s::$%s',
            $property->getDeclaringClass()->getName(),
            $property->getName(),
        ));
    }

    /** @return Shape */
    private function forReflectionType(\ReflectionType $type, string $context): array
    {
        if ($type->allowsNull()) {
            throw new \LogicException(sprintf('Normalization shape for %s cannot be nullable.', $context));
        }

        if ($type instanceof \ReflectionNamedType) {
            /** @var class-string $class */
            $class = $type->getName();
            return (
                $type->isBuiltin() ? ['kind' => 'scalar', 'types' => [$type->getName()]] : $this->forNamedType($class)
            );
        }

        if ($type instanceof \ReflectionUnionType) {
            $types = [];
            foreach ($type->getTypes() as $member) {
                if (!$member instanceof \ReflectionNamedType || !$member->isBuiltin()) {
                    throw new \LogicException(sprintf(
                        'Normalization shape for %s has an unsupported union.',
                        $context,
                    ));
                }
                $types[] = $member->getName();
            }
            sort($types, SORT_STRING);

            return ['kind' => 'scalar', 'types' => array_values(array_unique($types))];
        }

        throw new \LogicException(sprintf(
            'Normalization shape for %s has an unsupported intersection type.',
            $context,
        ));
    }

    /**
     * @param class-string $class
     *
     * @return Shape
     */
    private function forNamedType(string $class): array
    {
        $runtimeNormalizer = $this->runtimeNormalizersByType[$class] ?? null;
        if (null !== $runtimeNormalizer) {
            return $this->forRuntimeNormalizer($runtimeNormalizer);
        }

        if (enum_exists($class)) {
            $reflection = new \ReflectionEnum($class);
            $backingType = $reflection->getBackingType();
            if (null === $backingType) {
                throw new \LogicException(sprintf('Non-backed enum %s has no scalar normalization shape.', $class));
            }

            return ['kind' => 'scalar', 'types' => [$backingType->getName()]];
        }

        $reflection = new \ReflectionClass($class);
        if ([] !== $reflection->getAttributes(AsValue::class)) {
            $properties = $this->instanceProperties($reflection);
            if (1 !== count($properties)) {
                throw new \LogicException(sprintf('AsValue class %s must have exactly one instance property.', $class));
            }

            return $this->forProperty($properties[0]);
        }

        $fields = [];
        foreach ($this->instanceProperties($reflection) as $property) {
            $fields[$property->getName()] = $this->forProperty($property);
        }
        if ([] === $fields) {
            throw new \LogicException(sprintf('Class %s has no instance properties to normalize.', $class));
        }

        return ['kind' => 'object', 'fields' => $fields];
    }

    /**
     * @param class-string<RuntimeNormalizerInterface> $normalizer
     *
     * @return Shape
     */
    private function forRuntimeNormalizer(string $normalizer): array
    {
        $method = new \ReflectionMethod($normalizer, 'toScalar');
        $type = $method->getReturnType();
        if ($type instanceof \ReflectionNamedType && 'array' === $type->getName()) {
            $docComment = $method->getDocComment();

            return $this->arrayShapeFromDocComment($normalizer, false === $docComment ? '' : $docComment);
        }
        if (null === $type) {
            throw new \LogicException(sprintf(
                'Runtime normalizer %s::toScalar() must declare a precise return type.',
                $normalizer,
            ));
        }

        return $this->forReflectionType($type, $normalizer . '::toScalar()');
    }

    /** @return Shape */
    private function arrayShapeFromDocComment(string $normalizer, string $docComment): array
    {
        $matches = [];
        if (1 !== preg_match('/@return\s+array\{([^}]+)\}/s', $docComment, $matches)) {
            throw new \LogicException(sprintf(
                'Runtime normalizer %s::toScalar() returning array must declare a closed PHPDoc array shape.',
                $normalizer,
            ));
        }

        $shapeBody = preg_replace('/^\s*\*\s?/m', '', $matches[1]) ?? $matches[1];
        $fields = [];
        $shapeFields = preg_split('/\s*,\s*/', trim($shapeBody, " \t\n\r\0\x0B,"));
        foreach (false === $shapeFields ? [] : $shapeFields as $field) {
            if ('' === trim($field)) {
                continue;
            }
            $parts = [];
            if (
                1 !== preg_match(
                    '/^([A-Za-z_][A-Za-z0-9_]*):\s*(int|string|int\|string|string\|int)$/',
                    trim($field),
                    $parts,
                )
            ) {
                throw new \LogicException(sprintf(
                    'Runtime normalizer %s has unsupported array-shape field "%s".',
                    $normalizer,
                    trim($field),
                ));
            }
            $types = explode('|', $parts[2]);
            sort($types, SORT_STRING);
            $fields[$parts[1]] = ['kind' => 'scalar', 'types' => array_values(array_unique($types))];
        }

        if ([] === $fields) {
            throw new \LogicException(sprintf('Runtime normalizer %s declares an empty array shape.', $normalizer));
        }

        return ['kind' => 'object', 'fields' => $fields];
    }

    /**
     * @return list<\ReflectionProperty>
     */
    private function instanceProperties(\ReflectionClass $reflection): array
    {
        $properties = array_values(array_filter(
            $reflection->getProperties(),
            static fn(\ReflectionProperty $property): bool => !$property->isStatic() && !$property->isVirtual(),
        ));
        usort(
            $properties,
            static fn(
                \ReflectionProperty $left,
                \ReflectionProperty $right,
            ): int => $left->getName() <=> $right->getName(),
        );

        return $properties;
    }
}
