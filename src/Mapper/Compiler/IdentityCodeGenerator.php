<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Ultra\Mapper\Exception\DenormalizationException;
use Ultra\Mapper\Path;

readonly class IdentityCodeGenerator implements CodeGenerator
{
    public function __construct(
        private Type $type,
        private bool $typeCheck,
    ) {}

    public function generateNormalization(string $objectVar): string
    {
        return $objectVar;
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
    {
        if ($this->typeCheck && $this->type instanceof BuiltinType) {
            $typeName = $this->type->getTypeIdentifier()->value;
            $typeCheck = match ($typeName) {
                'int' => "is_int({$dataVar})",
                'string' => "is_string({$dataVar})",
                'float' => "is_float({$dataVar})",
                'bool' => "is_bool({$dataVar})",
                default => '',
            };
            $expected = $typeName;
            $denormalizationException = DenormalizationException::class;
            if ($typeCheck) {
                return <<<PHP
                    ({$typeCheck}
                        ? {$dataVar}
                        : throw new \\{$denormalizationException}(
                            "Type mismatch at path {{$path->generateRuntimeContextCall()}}: expected {$expected}, got " . gettype({$dataVar})
                        )
                    )
                    PHP;
            }
        }

        return $dataVar;
    }

    public function getExpectedDenormalizedType(): Type
    {
        return $this->type;
    }
}
