<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\Uid\Uuid;
use Ultra\Mapper\Exception\DenormalizationException;
use Ultra\Mapper\Path;

class SymfonyUuidCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        return (
            $type instanceof ObjectType
            && class_exists(Uuid::class)
            && is_a($type->getClassName(), Uuid::class, true)
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

        return new class($type->getClassName()) implements CodeGenerator {
            public function __construct(
                private string $class,
            ) {}

            public function generateNormalization(string $objectVar): string
            {
                return "{$objectVar}->toRfc4122()";
            }

            public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
            {
                $denormalizationException = DenormalizationException::class;

                return <<<PHP
                    (is_string({$dataVar}) && \\{$this->class}::isValid({$dataVar})
                        ? \\{$this->class}::fromString({$dataVar})
                        : throw new \\{$denormalizationException}(
                            "Invalid UUID for {$this->class} at path {{$path->generateRuntimeContextCall()}}: expected a canonical UUID string"
                        )
                    )
                    PHP;
            }

            public function getExpectedDenormalizedType(): Type
            {
                return Type::string();
            }
        };
    }
}
