<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\EnumType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Ultra\Mapper\Path;

class BackedEnumPropertyCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        if ($type instanceof NullableType) {
            $type = $type->getWrappedType();
        }
        if (! $type instanceof ObjectType) {
            return false;
        }
        if (! enum_exists($type->getClassName())) {
            return false;
        }
        $ref = new \ReflectionEnum($type->getClassName());

        return $ref->isBacked();
    }

    public function build(Type $type, CodeGeneratorBuilderRegistry $registry, CodeGeneratorContext $context): CodeGenerator
    {
        $enumType = $type;
        $isNullable = false;
        if ($type instanceof NullableType) {
            $enumType = $type->getWrappedType();
            $isNullable = true;
        }
        if (! $enumType instanceof EnumType) {
            throw new \InvalidArgumentException('Type must be EnumType');
        }
        $enumClass = $enumType->getClassName();
        $ref = new \ReflectionEnum($enumClass);
        $backingType = $ref->getBackingType()?->getName();
        $denormalizedType = match ($backingType) {
            'int' => Type::int(),
            'string' => Type::string(),
            default => throw new \RuntimeException('Cannot denormalize a non backed enum'),
        };

        return new class ($enumClass, $isNullable, $denormalizedType) implements CodeGenerator {
            public function __construct(private string $enumClass, private bool $isNullable, private Type $denormalizedType) {}

            public function generateNormalization(string $objectVar): string
            {
                if ($this->isNullable) {
                    return "{$objectVar}?->value";
                }

                return "{$objectVar}->value";

            }

            public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
            {
                if ($this->isNullable) {
                    return "{$dataVar} !== null ? \\{$this->enumClass}::from({$dataVar}) : null";
                }

                return "\\{$this->enumClass}::from({$dataVar})";

            }

            public function getExpectedDenormalizedType(): Type
            {
                return $this->isNullable ? Type::nullable($this->denormalizedType) : $this->denormalizedType;
            }
        };
    }
}
