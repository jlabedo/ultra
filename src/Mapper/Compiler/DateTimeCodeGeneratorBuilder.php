<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Ultra\Mapper\Path;

class DateTimeCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    private const array SUPPORTED_CLASSES = [
        \DateTime::class,
        \DateTimeImmutable::class,
        \DateTimeInterface::class,
    ];

    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        if (!$type instanceof ObjectType) {
            return false;
        }
        $class = $type->getClassName();

        return \in_array($class, self::SUPPORTED_CLASSES, true);
    }

    public function build(
        Type $type,
        CodeGeneratorBuilderRegistry $registry,
        CodeGeneratorContext $context,
    ): CodeGenerator {
        if (!$type instanceof ObjectType) {
            throw new \InvalidArgumentException('Type must be ObjectType');
        }

        $class = \DateTimeInterface::class === $type->getClassName()
            ? \DateTimeImmutable::class
            : $type->getClassName();

        return new class($class, $type->isNullable()) implements CodeGenerator {
            public function __construct(
                private string $class,
                private bool $isNullable,
            ) {}

            public function generateNormalization(string $objectVar): string
            {
                if ($this->isNullable) {
                    return "{$objectVar}?->format('Y-m-d\\TH:i:s.uP')";
                }

                return "{$objectVar}->format('Y-m-d\\TH:i:s.uP')";
            }

            public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
            {
                if ($this->isNullable) {
                    return "{$dataVar} !== null ? new \\{$this->class}({$dataVar}) : null";
                }

                return "new \\{$this->class}({$dataVar})";
            }

            public function getExpectedDenormalizedType(): Type
            {
                $type = Type::string();

                return $this->isNullable ? Type::nullable($type) : $type;
            }
        };
    }
}
